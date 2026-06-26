<?php
declare(strict_types=1);

namespace wise\workflow;

/**
 * 工作流引擎（Workflow Engine）
 * 
 * 工作流的核心执行引擎
 * 负责验证、执行工作流转换，并记录历史
 * 
 * 支持功能：
 * - 转换执行（apply）/ 能力检查（can）/ 模拟执行（dryRun）
 * - 乐观锁并发控制
 * - 工作流版本校验
 * - 5 级事件生命周期：before → guard → after → enter/leave → completed
 * - 回滚（rollback）/ 委托（delegate）
 * - 声明式条件表达式守卫
 * - 批量查询可执行转换（getEnabledTransitions）
 * - 并行审批任务感知（TaskManager 集成）
 * 
 * @package wise\workflow
 */
class WorkflowEngine
{
    /**
     * 状态存储对象
     * @var MarkingStoreInterface
     */
    protected $markingStore;
    
    /**
     * 事件服务（必须实现 trigger(string, array): void）
     * @var object|null
     */
    protected $eventService;
    
    /**
     * 权限服务
     * @var object|null
     */
    protected $permissionService;

    /**
     * 历史记录模型
     * @var object|null
     */
    protected $historyModel;

    /**
     * 乐观锁配置
     * @var array
     */
    protected $lockConfig;

    /**
     * 任务管理器（可选，用于并行审批支持）
     * @var TaskManager|null
     */
    protected $taskManager;

    /**
     * 构造函数
     */
    public function __construct(
        MarkingStoreInterface $markingStore,
        $eventService = null,
        $permissionService = null,
        $historyModel = null,
        ?TaskManager $taskManager = null
    ) {
        $this->markingStore = $markingStore;
        $this->eventService = $eventService;
        $this->permissionService = $permissionService;
        $this->historyModel = $historyModel;
        $this->taskManager  = $taskManager;
        $this->lockConfig = config('wise-workflow.lock', ['enabled' => true, 'max_retries' => 3]);
    }

    /**
     * 设置任务管理器
     */
    public function setTaskManager(TaskManager $taskManager): void
    {
        $this->taskManager = $taskManager;
        $taskManager->setEngine($this);
    }

    /**
     * 获取任务管理器
     */
    public function getTaskManager(): ?TaskManager
    {
        return $this->taskManager;
    }

    /**
     * 执行工作流转换
     *
     * 完整流程：
     * 1. 读取当前状态 + 版本号
     * 2. 版本校验
     * 3. 触发 before 事件（可被监听器中止）
     * 4. 权限检查
     * 5. 守卫条件检查
     * 6. 执行状态转换
     * 7. 乐观锁保存
     * 8. 记录历史
     * 9. 触发 after / enter / leave / completed 事件
     *
     * @param Workflow $workflow       工作流定义
     * @param string   $subject        主体标识
     * @param string   $transitionName 转换名称
     * @param array    $context        上下文
     * @return array 转换后的新标记
     * @throws exception\WorkflowException
     */
    public function apply(Workflow $workflow, string $subject, string $transitionName, array $context = []): array
    {
        $transition = $this->findTransition($workflow, $transitionName);

        // Phase 3a: 并行审批保护——有未完成 task 时禁止直接 apply
        if ($this->taskManager) {
            $pendingTasks = $this->taskManager->getPendingTasksForTransition($subject, $transitionName);
            if (!empty($pendingTasks)) {
                throw new exception\WorkflowException(
                    "转换 [{$transitionName}] 存在未完成的并行审批任务，请通过 TaskManager::completeTask 完成审批"
                );
            }
        }

        // 注入上下文信息
        $context['transition_name'] = $transitionName;
        $context['workflow_name']   = $workflow->getName();
        $context['definition_version'] = $workflow->getVersion();

        $maxRetries = $this->lockConfig['max_retries'] ?? 3;
        $lastException = null;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                $marking = $this->markingStore->getMarking($subject);
                $currentVersion = $this->markingStore->getVersion($subject);

                // 版本校验
                $this->checkVersion($workflow, $subject, $marking);

                // before 事件
                $this->dispatchBeforeEvent($workflow, $transition, $subject, $context);

                // 权限检查
                if ($this->permissionService) {
                    $this->checkPermission($workflow, $transition, $context);
                }

                // 守卫条件检查（支持 callable + guard_expression）
                $guard = WorkflowRegistry::buildGuard($transition);
                if ($guard && !$guard($marking, $context)) {
                    $this->dispatchGuardFailedEvent($workflow, $transition, $subject, $context);
                    throw new exception\WorkflowException("守卫条件不满足 [{$transitionName}]");
                }

                // 执行转换
                $fromPlaces = $transition->getFrom();
                $toPlaces   = $transition->getTo();

                // 验证 from 状态
                foreach ($fromPlaces as $from) {
                    if (!isset($marking[$from])) {
                        throw new exception\WorkflowException(
                            "当前状态不匹配：需要 [{$from}]，实际处于 [" . implode(',', array_keys($marking)) . "]"
                        );
                    }
                }

                // 移除源状态，添加目标状态
                $oldMarking = $marking;
                foreach ($fromPlaces as $from) {
                    unset($marking[$from]);
                }
                foreach ($toPlaces as $to) {
                    $marking[$to] = 1;
                }

                // 乐观锁保存
                $lockEnabled = $this->lockConfig['enabled'] ?? true;
                $expectedVersion = $lockEnabled ? $currentVersion : 0;
                $newVersion = $this->markingStore->setMarking($subject, $marking, $context, $expectedVersion);

                // 记录历史
                $fromStr = implode(',', array_keys($oldMarking));
                $toStr   = implode(',', array_keys($marking));
                $this->markingStore->recordHistory(
                    $subject, $transitionName, $fromStr, $toStr, $context,
                    model\WorkflowHistory::TYPE_NORMAL
                );

                // after 事件
                $this->dispatchAfterEvent($workflow, $transition, $subject, $context);

                // enter/leave 事件
                $this->dispatchPlaceEvents($workflow, $oldMarking, $marking, $subject, $context);

                // completed 事件（检查是否到达终态）
                if ($this->isCompleted($workflow, $marking)) {
                    $this->dispatchCompletedEvent($workflow, $subject, $context);
                }

                return $marking;

            } catch (exception\WorkflowException $e) {
                // 乐观锁冲突时重试
                if (strpos($e->getMessage(), '乐观锁冲突') !== false && $retry < $maxRetries) {
                    $lastException = $e;
                    usleep(rand(50000, 200000)); // 50-200ms 随机退避
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new exception\WorkflowException("超过最大重试次数 [{$transitionName}]");
    }

    /**
     * 模拟执行（不落库，返回模拟结果）
     *
     * @param Workflow $workflow
     * @param string   $subject
     * @param string   $transitionName
     * @param array    $context
     * @return array ['can' => bool, 'marking' => array, 'reason' => string]
     */
    public function dryRun(Workflow $workflow, string $subject, string $transitionName, array $context = []): array
    {
        $transition = $this->findTransition($workflow, $transitionName);
        $marking    = $this->markingStore->getMarking($subject);

        // 检查 from 状态
        foreach ($transition->getFrom() as $from) {
            if (!isset($marking[$from])) {
                return [
                    'can'    => false,
                    'marking' => $marking,
                    'reason' => "缺少前置状态 [{$from}]",
                ];
            }
        }

        // 检查守卫
        $context['transition_name'] = $transitionName;
        $context['workflow_name']   = $workflow->getName();

        $guard = WorkflowRegistry::buildGuard($transition);
        if ($guard && !$guard($marking, $context)) {
            return [
                'can'    => false,
                'marking' => $marking,
                'reason' => '守卫条件不满足',
            ];
        }

        // 模拟转换
        $simMarking = $marking;
        foreach ($transition->getFrom() as $from) {
            unset($simMarking[$from]);
        }
        foreach ($transition->getTo() as $to) {
            $simMarking[$to] = 1;
        }

        return [
            'can'     => true,
            'marking' => $simMarking,
            'reason'  => 'OK',
        ];
    }

    /**
     * 检查是否可以执行某个转换
     */
    public function can(Workflow $workflow, string $subject, string $transitionName, array $context = []): bool
    {
        // Phase 3a: 并行审批任务感知——有未完成 task 时不允许直接 apply
        if ($this->taskManager) {
            $pendingTasks = $this->taskManager->getPendingTasksForTransition($subject, $transitionName);
            if (!empty($pendingTasks)) {
                return false;
            }
        }

        $marking = $this->markingStore->getMarking($subject);
        try {
            $transition = $this->findTransition($workflow, $transitionName);
            foreach ($transition->getFrom() as $from) {
                if (!isset($marking[$from])) {
                    return false;
                }
            }
            $guard = WorkflowRegistry::buildGuard($transition);
            if ($guard) {
                return $guard($marking, $context);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 批量获取可执行转换名称（比遍历 can 更高效）
     */
    public function canAny(Workflow $workflow, string $subject, array $context = []): array
    {
        $marking    = $this->markingStore->getMarking($subject);
        $enabled    = [];

        foreach ($workflow->getTransitions() as $transition) {
            // 快速检查 from
            $ok = true;
            foreach ($transition->getFrom() as $from) {
                if (!isset($marking[$from])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) continue;

            // 守卫检查
            $guard = WorkflowRegistry::buildGuard($transition);
            if ($guard && !$guard($marking, $context)) {
                continue;
            }

            $enabled[] = $transition->getName();
        }
        return $enabled;
    }

    /**
     * 获取可用的转换列表
     */
    public function getEnabledTransitions(Workflow $workflow, string $subject): array
    {
        $enabled = [];
        foreach ($workflow->getTransitions() as $transition) {
            if ($this->can($workflow, $subject, $transition->getName())) {
                $enabled[] = $transition;
            }
        }

        // 按 sort 排序
        usort($enabled, function ($a, $b) {
            return $a->getSort() - $b->getSort();
        });

        return $enabled;
    }

    /**
     * 回滚到上一个状态
     * 
     * 从历史记录中找到最近一次转换，反向操作
     *
     * @param Workflow $workflow
     * @param string   $subject
     * @param array    $context
     * @return array 回滚后的标记
     */
    public function rollback(Workflow $workflow, string $subject, array $context = []): array
    {
        // 查找最近一条历史记录
        $historyModel = $this->historyModel ?: new model\WorkflowHistory();
        $lastRecord = $historyModel
            ->where('subject', $subject)
            ->where('type', model\WorkflowHistory::TYPE_NORMAL)
            ->order('id', 'desc')
            ->find();

        if (!$lastRecord) {
            throw new exception\WorkflowException("没有可回滚的历史记录 [{$subject}]");
        }

        // 检查当前转换是否可撤回
        $transitionName = $lastRecord->transition_name;
        try {
            $transition = $this->findTransition($workflow, $transitionName);
            if (!$transition->isReversible()) {
                throw new exception\WorkflowException("转换 [{$transitionName}] 不支持撤回");
            }
        } catch (exception\WorkflowException $e) {
            if (strpos($e->getMessage(), 'not found') !== false) {
                // transition 已删除，仍然允许回滚
            } else {
                throw $e;
            }
        }

        $fromPlaces = explode(',', $lastRecord->from_place);
        $toPlaces   = explode(',', $lastRecord->to_place);

        $marking = $this->markingStore->getMarking($subject);
        $currentVersion = $this->markingStore->getVersion($subject);

        // 恢复旧状态
        foreach ($toPlaces as $p) {
            unset($marking[trim($p)]);
        }
        foreach ($fromPlaces as $p) {
            $marking[trim($p)] = 1;
        }

        // 乐观锁保存
        $lockEnabled = $this->lockConfig['enabled'] ?? true;
        $expectedVersion = $lockEnabled ? $currentVersion : 0;
        $context['remark'] = $context['remark'] ?? '系统回滚';

        $this->markingStore->setMarking($subject, $marking, $context, $expectedVersion);
        $this->markingStore->recordHistory(
            $subject, '__rollback__',
            implode(',', $toPlaces), implode(',', $fromPlaces),
            $context, model\WorkflowHistory::TYPE_ROLLBACK
        );

        // 触发回滚事件
        if ($this->eventService) {
            $this->eventService->trigger("workflow.{$workflow->getName()}.rollback", [
                'workflow' => $workflow,
                'subject'  => $subject,
                'context'  => $context,
                'from'     => $toPlaces,
                'to'       => $fromPlaces,
            ]);
        }

        // Phase 3a: 回滚时取消关联的待处理任务
        if ($this->taskManager) {
            $this->taskManager->cancelTasks($subject);
        }

        return $marking;
    }

    /**
     * 委托/转交任务
     *
     * @param Workflow $workflow
     * @param string   $subject
     * @param int      $toUserId  目标用户ID
     * @param array    $context   上下文（含 remark 等）
     * @return void
     */
    public function delegate(Workflow $workflow, string $subject, int $toUserId, array $context = []): void
    {
        $marking = $this->markingStore->getMarking($subject);
        if (empty($marking)) {
            throw new exception\WorkflowException("工作流实例不存在 [{$subject}]");
        }

        $this->markingStore->setAssignee($subject, $toUserId);

        $currentPlaces = implode(',', array_keys($marking));
        $this->markingStore->recordHistory(
            $subject, '__delegate__',
            $currentPlaces, $currentPlaces,
            array_merge($context, ['to_user' => $toUserId]),
            model\WorkflowHistory::TYPE_DELEGATE
        );

        // 触发委托事件
        if ($this->eventService) {
            $this->eventService->trigger("workflow.{$workflow->getName()}.delegate", [
                'workflow' => $workflow,
                'subject'  => $subject,
                'to_user'  => $toUserId,
                'context'  => $context,
            ]);
        }
    }

    /**
     * 获取当前标记
     */
    public function getMarking(string $subject): array
    {
        return $this->markingStore->getMarking($subject);
    }

    /**
     * 设置标记
     */
    public function setMarking(string $subject, array $marking, array $context = []): void
    {
        $this->markingStore->setMarking($subject, $marking, $context);
    }

    /**
     * 初始化工作流实例
     */
    public function init(Workflow $workflow, string $subject, array $context = []): void
    {
        $initialPlace = $workflow->getInitialPlace();
        if (!$initialPlace) {
            throw new exception\WorkflowException("工作流 [{$workflow->getName()}] 未定义初始状态");
        }

        $marking = $this->markingStore->getMarking($subject);
        if (!empty($marking)) {
            return;
        }

        $context['definition_version'] = $workflow->getVersion();
        $this->markingStore->setMarking($subject, [$initialPlace => 1], $context);

        // 记录初始化历史
        $this->markingStore->recordHistory(
            $subject, '__init__', '', $initialPlace, $context,
            model\WorkflowHistory::TYPE_INIT
        );
    }

    /**
     * 获取转换历史
     */
    public function getHistory(string $subject, int $limit = 50): array
    {
        $historyModel = $this->historyModel ?: new model\WorkflowHistory();
        return $historyModel->where('subject', $subject)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    // ───────────────────── Phase 3b — 并行审批委托方法 ─────────────────────

    /**
     * 创建并行审批任务（委托到 TaskManager）
     *
     * @param Workflow $workflow       工作流定义
     * @param string   $subject        主体标识
     * @param string   $transitionName 审批通过后触发的转换名称
     * @param int[]    $assignees      审批人用户ID列表
     * @param string   $policy         审批策略：all/any/ratio/count
     * @param mixed    $policyValue    策略参数值（ratio 时为 0.0~1.0，count 时为整数）
     * @param array    $context        上下文信息
     * @return array   创建的任务列表
     */
    public function createTasks(
        Workflow $workflow,
        string $subject,
        string $transitionName,
        array $assignees,
        string $policy = 'all',
        $policyValue = null,
        array $context = []
    ): array {
        $this->ensureTaskManager();
        return $this->taskManager->createTasks(
            $workflow, $subject, $transitionName, $assignees, $policy, $policyValue, $context
        );
    }

    /**
     * 完成一个审批任务（委托到 TaskManager）
     *
     * @param string $subject        主体标识
     * @param string $transitionName 转换名称
     * @param string $taskKey        任务标识
     * @param int    $userId         完成人用户ID
     * @param string $result         处理结果（approved / rejected）
     * @param array  $context        上下文（含 comment/remark 等）
     * @return bool  是否触发状态转换
     */
    public function completeTask(
        string $subject,
        string $transitionName,
        string $taskKey,
        int $userId,
        string $result,
        array $context = []
    ): bool {
        $this->ensureTaskManager();
        return $this->taskManager->completeTask(
            $subject, $transitionName, $taskKey, $userId, $result, $context
        );
    }

    /**
     * 获取待处理并行审批任务
     *
     * @param string $subject 主体标识
     * @return array
     */
    public function getPendingTasks(string $subject): array
    {
        if (!$this->taskManager) {
            return [];
        }
        return $this->taskManager->getPendingTasks($subject);
    }

    /**
     * 获取某用户的待审批任务列表
     *
     * @param int    $userId 用户ID
     * @param string $status 状态过滤（空=所有）
     * @return array
     */
    public function getUserTasks(int $userId, string $status = ''): array
    {
        if (!$this->taskManager) {
            return [];
        }
        return $this->taskManager->getUserTasks($userId, $status);
    }

    /**
     * 获取转换的待处理任务
     *
     * @param string $subject
     * @param string $transitionName
     * @return array
     */
    public function getPendingTasksForTransition(string $subject, string $transitionName): array
    {
        if (!$this->taskManager) {
            return [];
        }
        return $this->taskManager->getPendingTasksForTransition($subject, $transitionName);
    }

    /**
     * 取消待处理任务
     *
     * @param string $subject
     * @param string $transitionName 空=取消所有
     * @return bool
     */
    public function cancelTasks(string $subject, string $transitionName = ''): bool
    {
        $this->ensureTaskManager();
        return $this->taskManager->cancelTasks($subject, $transitionName);
    }

    /**
     * 转交审批任务
     *
     * @param string $subject
     * @param string $transitionName
     * @param string $taskKey
     * @param int    $fromUserId 原处理人
     * @param int    $toUserId   新处理人
     * @return bool
     */
    public function delegateTask(
        string $subject,
        string $transitionName,
        string $taskKey,
        int $fromUserId,
        int $toUserId
    ): bool {
        $this->ensureTaskManager();
        return $this->taskManager->delegateTask(
            $subject, $transitionName, $taskKey, $fromUserId, $toUserId
        );
    }

    /**
     * 确保 TaskManager 已注入
     */
    protected function ensureTaskManager(): void
    {
        if (!$this->taskManager) {
            $this->taskManager = new TaskManager();
            $this->taskManager->setEngine($this);
        }
    }

    // ───────────────────── Protected Helpers ─────────────────────

    /**
     * 查找转换
     */
    protected function findTransition(Workflow $workflow, string $transitionName): Transition
    {
        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->getName() === $transitionName) {
                return $transition;
            }
        }
        throw new exception\WorkflowException("转换 [{$transitionName}] 在工作流 [{$workflow->getName()}] 中不存在");
    }

    /**
     * 版本校验
     */
    protected function checkVersion(Workflow $workflow, string $subject, array $marking): void
    {
        if (empty($marking)) {
            return; // 未初始化，跳过
        }

        $defVersion = $workflow->getVersion();
        if ($defVersion <= 0) {
            return; // 无版本号
        }

        $policy = $workflow->getVersionPolicy();

        if ($policy === 'strict') {
            $markingRecord = (new model\WorkflowMarking())->where('subject', $subject)->find();
            if ($markingRecord && isset($markingRecord->definition_version)) {
                $instanceVersion = (int)$markingRecord->definition_version;
                if ($instanceVersion > 0 && $instanceVersion !== $defVersion) {
                    throw new exception\WorkflowException(
                        "工作流定义版本不一致：实例版本 [{$instanceVersion}]，当前定义版本 [{$defVersion}]",
                        ['instance_version' => $instanceVersion, 'definition_version' => $defVersion]
                    );
                }
            }
        }
        // migrate 和 legacy 策略由业务层自行处理
    }

    /**
     * 检查是否到达终态
     */
    protected function isCompleted(Workflow $workflow, array $marking): bool
    {
        $places = $workflow->getPlaces();
        foreach ($marking as $placeName => $val) {
            if (isset($places[$placeName]) && $places[$placeName]->isEnded()) {
                return true;
            }
        }

        // 另一种判断：没有任何可用的出边
        $hasOutgoing = false;
        foreach ($workflow->getTransitions() as $t) {
            foreach ($t->getFrom() as $from) {
                if (isset($marking[$from])) {
                    $hasOutgoing = true;
                    break 2;
                }
            }
        }
        return !$hasOutgoing && !empty($marking);
    }

    /**
     * 权限检查
     */
    protected function checkPermission(Workflow $workflow, Transition $transition, array $context): void
    {
        if ($this->permissionService === null) {
            return;
        }
        if (method_exists($this->permissionService, 'check')) {
            $this->permissionService->check($transition, $context);
        }
    }

    // ───────────────────── Event Dispatch ─────────────────────

    /**
     * before 事件（转换前，监听器 throw 可中止）
     */
    protected function dispatchBeforeEvent(Workflow $workflow, Transition $transition, string $subject, array $context): void
    {
        if (!$this->eventService) return;
        $this->eventService->trigger("workflow.{$workflow->getName()}.before.{$transition->getName()}", [
            'workflow'   => $workflow,
            'transition' => $transition,
            'subject'    => $subject,
            'context'    => $context,
        ]);
    }

    /**
     * after 事件（转换后）
     */
    protected function dispatchAfterEvent(Workflow $workflow, Transition $transition, string $subject, array $context): void
    {
        if (!$this->eventService) return;
        $this->eventService->trigger("workflow.{$workflow->getName()}.after.{$transition->getName()}", [
            'workflow'   => $workflow,
            'transition' => $transition,
            'subject'    => $subject,
            'context'    => $context,
        ]);
    }

    /**
     * guard_failed 事件
     */
    protected function dispatchGuardFailedEvent(Workflow $workflow, Transition $transition, string $subject, array $context): void
    {
        if (!$this->eventService) return;
        $this->eventService->trigger("workflow.{$workflow->getName()}.guard_failed", [
            'workflow'    => $workflow,
            'transition'  => $transition,
            'subject'     => $subject,
            'context'     => $context,
            'event_name'  => "workflow.{$workflow->getName()}.guard_failed",
        ]);
    }

    /**
     * enter/leave 事件
     */
    protected function dispatchPlaceEvents(Workflow $workflow, array $oldMarking, array $newMarking, string $subject, array $context): void
    {
        if (!$this->eventService) return;

        // leave 事件
        foreach ($oldMarking as $placeName => $val) {
            if (!isset($newMarking[$placeName])) {
                $this->eventService->trigger("workflow.{$workflow->getName()}.leave.{$placeName}", [
                    'workflow'   => $workflow,
                    'place'      => $placeName,
                    'subject'    => $subject,
                    'context'    => $context,
                    'event_name' => "workflow.{$workflow->getName()}.leave.{$placeName}",
                ]);
            }
        }

        // enter 事件
        foreach ($newMarking as $placeName => $val) {
            if (!isset($oldMarking[$placeName])) {
                $this->eventService->trigger("workflow.{$workflow->getName()}.enter.{$placeName}", [
                    'workflow'   => $workflow,
                    'place'      => $placeName,
                    'subject'    => $subject,
                    'context'    => $context,
                    'event_name' => "workflow.{$workflow->getName()}.enter.{$placeName}",
                ]);
            }
        }
    }

    /**
     * completed 事件
     */
    protected function dispatchCompletedEvent(Workflow $workflow, string $subject, array $context): void
    {
        if (!$this->eventService) return;
        $this->eventService->trigger("workflow.{$workflow->getName()}.completed", [
            'workflow'   => $workflow,
            'subject'    => $subject,
            'context'    => $context,
            'event_name' => "workflow.{$workflow->getName()}.completed",
        ]);
    }
}
