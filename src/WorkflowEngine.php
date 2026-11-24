<?php
declare(strict_types=1);

namespace wise\workflow;

use wise\support\runtime\Runtime;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\model\Transition;
use wise\workflow\task\TaskManager;

/**
 * 工作流引擎（Workflow Engine）
 *
 * 工作流的核心执行引擎，V2 升级为 Pipeline 架构。
 *
 * 核心变化（V2）：
 * - apply() → 通过 TransitionPipeline 编排 13 个 Pipe 执行
 * - 插件可通过 WorkflowRegistry::registerPipe() 插入自定义步骤
 * - can() / dryRun() / canAny() 保持轻量，不经过完整管道
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
 * - 插件可注册自定义 Pipe（V2 新增）
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
     * 可信主体解析器（安全审计阶段 3 / C-1，可选）
     * @var contract\PrincipalResolverInterface|null
     */
    protected $principalResolver = null;

    /**
     * 实例归属判定（安全审计阶段 3 / C-1，可选）
     * @var contract\SubjectOwnershipInterface|null
     */
    protected $ownershipService = null;

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
     * V2: 转换管道（由 Engine 管理，供 apply() 使用）
     * @var TransitionPipeline
     */
    protected $pipeline;

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
        $this->pipeline = new TransitionPipeline($markingStore, $eventService, $permissionService, $historyModel, $taskManager);
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
     * 执行工作流转换（V2: Pipeline 编排）
     *
     * 将原来的 200 行巨型方法拆分为可编排的管道链：
     * FindTransition → CheckPendingTasks → LoadMarking → VerifyVersion
     * → DispatchBeforeEvent → CheckPermission → EvaluateGuards
     * → ValidateFromPlaces → CheckConfirm → TransitionState → OptimisticLockSave
     * → RecordHistory → DispatchAfterEvents
     *
     * 插件可通过 WorkflowRegistry 注册自定义 Pipe 到任意位置。
     * 乐观锁冲突由外层重试循环处理。
     *
     * @param Workflow $workflow       工作流定义
     * @param string   $subject        主体标识
     * @param string   $transitionName 转换名称
     * @param array       $context       上下文（调用方数据；保留键 auto_sla 会被剥离）
     * @param string|null $systemTrigger 系统触发原因（如 'auto_reject' / 'escalate'）。
     *                                   只有引擎参数可信，用于跳过用户权限校验
     * @return array 转换后的新标记
     * @throws exception\WorkflowException
     */
    public function apply(
        Workflow $workflow,
        string $subject,
        string $transitionName,
        array $context = [],
        ?string $systemTrigger = null
    ): array {
        $context['transition_name']    = $transitionName;
        $context['workflow_name']      = $workflow->getName();
        $context['definition_version'] = $workflow->getVersion();

        unset($context['auto_sla']);
        unset($context['operator']);
        if ($systemTrigger !== null && $systemTrigger !== '') {
            $context['auto_sla'] = $systemTrigger;
            $context['operator'] = 'system:' . $systemTrigger;
        }

        $context['subject'] = $subject;
        unset($context['user_id']);
        $principalId = $this->resolvePrincipalId();
        if ($principalId > 0) {
            $context['user_id'] = $principalId;
        }

        if ($systemTrigger === null || $systemTrigger === '') {
            $this->assertOwnership($subject, $context);
        }

        $payload = new contract\TransitionPayload($workflow, $subject, $transitionName, $context);

        $maxRetries    = $this->lockConfig['max_retries'] ?? 3;
        $lastException = null;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                $result = (new TransitionPipeline(
                    $this->markingStore,
                    $this->eventService,
                    $this->permissionService,
                    $this->historyModel,
                    $this->taskManager
                ))
                    ->send($payload)
                    ->through($this->resolvePipes($workflow))
                    ->then(function (contract\TransitionPayload $p) {
                        return $p;
                    });

                return $result->marking;

            } catch (exception\WorkflowException $e) {
                if (strpos($e->getMessage(), '乐观锁冲突') !== false && $retry < $maxRetries) {
                    $lastException = $e;
                    Runtime::sleep(rand(50000, 200000) / 1_000_000);
                    continue;
                }
                throw $e;
            }
        }

        throw $lastException ?? new exception\WorkflowException("超过最大重试次数 [{$transitionName}]");
    }

    /**
     * 解析管道工序列表（V2 新增）
     *
     * 合并三类管道来源：
     *   1. 配置文件 pipelines.{type} 或 pipelines.default
     *   2. 配置文件 pipe_slots 中的命名插槽
     *   3. WorkflowRegistry 中插件注册的 Pipe
     *
     * @param Workflow $workflow
     * @return array<string> 管道类名列表
     */
    protected function resolvePipes(Workflow $workflow): array
    {
        $pipelineType = $workflow->getMeta('pipeline', 'default');
        $pipes = config("wise-workflow.pipelines.{$pipelineType}");

        if (empty($pipes)) {
            $pipes = config('wise-workflow.pipelines.default', []);
        }

        if (empty($pipes)) {
            $pipes = $this->getDefaultPipes();
        }

        $slots = config('wise-workflow.pipe_slots', []);
        $pipes = $this->mergePipesWithSlots($pipes, $slots);

        $pluginPipes = WorkflowRegistry::getRegisteredPipes();
        $pipes = $this->mergePipesWithSlots($pipes, $pluginPipes);

        return $pipes;
    }

    /**
     * 获取默认管道列表（V2 新增）
     *
     * @return array<string>
     */
    public function getDefaultPipes(): array
    {
        return [
            pipes\FindTransitionPipe::class,
            pipes\CheckPendingTasksPipe::class,
            pipes\LoadMarkingPipe::class,
            pipes\VerifyVersionPipe::class,
            pipes\DispatchBeforeEventPipe::class,
            pipes\CheckPermissionPipe::class,
            pipes\EvaluateGuardsPipe::class,
            pipes\ValidateFromPlacesPipe::class,
            pipes\CheckConfirmPipe::class,
            pipes\TransitionStatePipe::class,
            pipes\OptimisticLockSavePipe::class,
            pipes\RecordHistoryPipe::class,
            pipes\DispatchAfterEventsPipe::class,
        ];
    }

    /**
     * 合并管道与插槽（V2 新增）
     *
     * 将命名插槽中的 Pipe 插入到指定位置。
     * 插槽名对应管道列表中某个 Pipe 类名，插件 Pipe 会被插入到
     * 该 Pipe 的前面（before_）或后面（after_）。
     *
     * @param array $pipes 原始管道列表
     * @param array $slots 插槽配置 {'before_xxx': ['pipe1', 'pipe2'], 'after_xxx': ['pipe3']}
     * @return array
     */
    protected function mergePipesWithSlots(array $pipes, array $slots): array
    {
        if (empty($slots)) {
            return $pipes;
        }

        $result = [];
        foreach ($pipes as $pipe) {
            $baseName = $this->getPipeBaseName($pipe);

            $beforeKey = 'before_' . $baseName;
            if (isset($slots[$beforeKey])) {
                foreach ((array)$slots[$beforeKey] as $pluginPipe) {
                    $result[] = $pluginPipe;
                }
            }

            $result[] = $pipe;

            $afterKey = 'after_' . $baseName;
            if (isset($slots[$afterKey])) {
                foreach ((array)$slots[$afterKey] as $pluginPipe) {
                    $result[] = $pluginPipe;
                }
            }
        }

        return $result;
    }

    /**
     * 提取 Pipe 类名中的基础名称（V2 新增）
     *
     * @param string $fullClass
     * @return string
     */
    protected function getPipeBaseName(string $fullClass): string
    {
        $parts = explode('\\', $fullClass);
        $className = end($parts);
        return str_replace('Pipe', '', $className);
    }

    /**
     * 获取转换管道实例（V2 新增，供外部扩展）
     */
    public function getPipeline(): TransitionPipeline
    {
        return $this->pipeline;
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

        foreach ($transition->getFrom() as $from) {
            if (!isset($marking[$from])) {
                return [
                    'can'    => false,
                    'marking' => $marking,
                    'reason' => "缺少前置状态 [{$from}]",
                ];
            }
        }

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
            $ok = true;
            foreach ($transition->getFrom() as $from) {
                if (!isset($marking[$from])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) continue;

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
        $marking = $this->markingStore->getMarking($subject);
        $pending = $this->taskManager ? $this->taskManager->getPendingTasks($subject) : [];
        $pendingTransitions = array_column($pending, 'transition_name');

        $enabled = [];
        foreach ($workflow->getTransitions() as $transition) {
            if (in_array($transition->getName(), $pendingTransitions, true)) {
                continue;
            }

            $ok = true;
            foreach ($transition->getFrom() as $from) {
                if (!isset($marking[$from])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }

            try {
                $guard = WorkflowRegistry::buildGuard($transition);
                if ($guard && !$guard($marking, [])) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }

            $enabled[] = $transition;
        }

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
    /**
     * 统一授权点（安全审计阶段 2 / D-1、D-2）
     *
     * `rollback` / `delegate` / `cancelTasks` 与管道内的 `CheckPermissionPipe`
     * 使用**同一**权限契约，消除「同一效果存在免检路径」。
     *
     * 权限服务缺失时的语义与管道保持一致（fail-closed）：
     * 除非显式配置 `wise-workflow.permission.allow_all = true`，否则拒绝。
     *
     * @param string $operation 操作名（__rollback__ / __delegate__ / __cancel_tasks__）
     * @param string $subject   工作流实例标识
     * @param array  $context   上下文（会注入 subject）
     * @throws exception\WorkflowException
     */
    protected function authorize(string $operation, string $subject, array $context = []): void
    {
        $context['subject'] = $subject;

        $this->assertOwnership($subject, $context);

        $permissionService = $this->permissionService;

        if ($permissionService === null) {
            if ((bool) config('wise-workflow.permission.allow_all', false)) {
                return;
            }

            throw new exception\WorkflowException(
                "权限校验失败：未注入权限服务，拒绝执行 {$operation}"
                . '（确实不需要权限系统时请配置 wise-workflow.permission.allow_all = true）'
            );
        }

        if ($permissionService instanceof contract\OperationAuthorizerInterface) {
            $allowed = $permissionService->authorizeOperation($operation, $subject, $context);
        } elseif (method_exists($permissionService, 'check')) {
            $allowed = (bool) $permissionService->check(
                new model\Transition($operation, [], []),
                $context
            );
        } else {
            throw new exception\WorkflowException(
                "权限校验失败：权限服务未实现 check() 方法，拒绝执行 {$operation}"
            );
        }

        if (!$allowed) {
            throw new exception\WorkflowException("权限校验失败：无权执行 {$operation} [{$subject}]");
        }
    }

    /**
     * 注入可信主体解析器（安全审计阶段 3 / C-1）
     */
    public function setPrincipalResolver(?contract\PrincipalResolverInterface $resolver): void
    {
        $this->principalResolver = $resolver;
    }

    /**
     * 注入实例归属判定（安全审计阶段 3 / C-1）
     */
    public function setOwnershipService(?contract\SubjectOwnershipInterface $ownership): void
    {
        $this->ownershipService = $ownership;
    }

    /**
     * 解析可信主体 ID（安全审计阶段 3 / C-1）
     *
     * 只认注入的解析器；解析器缺失或异常一律视为匿名（0）。
     */
    protected function resolvePrincipalId(): int
    {
        if (!$this->principalResolver) {
            return 0;
        }

        try {
            return (int) $this->principalResolver->getUserId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 实例归属校验（安全审计阶段 3 / C-1）
     *
     * 未注入归属服务时不生效——包无法替宿主判断业务归属，因此这是显式可选项；
     * 一旦注入，跨主体访问与匿名访问都会被拒绝（抛异常，而不是静默放行）。
     *
     * @throws exception\WorkflowException
     */
    protected function assertOwnership(string $subject, array $context = []): void
    {
        if (!$this->ownershipService) {
            return;
        }

        $userId = $this->resolvePrincipalId();
        $owned  = $userId > 0
            && (bool) $this->ownershipService->isOwnedBy($subject, $userId, $context);

        if (!$owned) {
            throw new exception\WorkflowException("无权访问工作流实例 [{$subject}]");
        }
    }

    /**
     * 供控制器读路径调用的归属校验入口（安全审计阶段 3 / C-1）
     *
     * 未配置归属服务时为空操作。
     */
    public function checkSubjectAccess(string $subject): void
    {
        $this->assertOwnership($subject, ['subject' => $subject]);
    }

    /**
     * 供控制器对「不进管道」的写操作复用统一授权点（安全审计阶段 2 / D-1）
     *
     * createTasks 这类不走 TransitionPipeline 的写入必须与 apply / rollback /
     * delegate / cancelTasks 使用同一套授权（权限服务 + 归属校验），
     * 否则它会成为同一效果的免检路径。
     *
     * @throws exception\WorkflowException
     */
    public function checkOperation(string $operation, string $subject, array $context = []): void
    {
        $this->authorize($operation, $subject, $context);
    }

    public function rollback(Workflow $workflow, string $subject, array $context = []): array
    {
        $this->authorize('__rollback__', $subject, $context);

        $historyModel = $this->historyModel ?: new model\WorkflowHistory();
        $lastRecord = $historyModel
            ->where('subject', $subject)
            ->where('type', model\WorkflowHistory::TYPE_NORMAL)
            ->order('id', 'desc')
            ->find();

        if (!$lastRecord) {
            throw new exception\WorkflowException("没有可回滚的历史记录 [{$subject}]");
        }

        $transitionName = $lastRecord->transition_name;
        try {
            $transition = $this->findTransition($workflow, $transitionName);
            if (!$transition->isReversible()) {
                throw new exception\WorkflowException("转换 [{$transitionName}] 不支持撤回");
            }
        } catch (exception\WorkflowException $e) {
            if (strpos($e->getMessage(), '不存在') !== false) {
            } else {
                throw $e;
            }
        }

        $fromPlaces = explode(',', $lastRecord->from_place);
        $toPlaces   = explode(',', $lastRecord->to_place);

        $marking = $this->markingStore->getMarking($subject);
        $currentVersion = $this->markingStore->getVersion($subject);

        foreach ($toPlaces as $p) {
            unset($marking[trim($p)]);
        }
        foreach ($fromPlaces as $p) {
            $marking[trim($p)] = 1;
        }

        $lockEnabled = $this->lockConfig['enabled'] ?? true;
        $expectedVersion = $lockEnabled ? $currentVersion : 0;
        $context['remark'] = $context['remark'] ?? '系统回滚';
        $maxRetries = $this->lockConfig['max_retries'] ?? 3;

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                $this->markingStore->setMarking($subject, $marking, $context, $expectedVersion);
                break;
            } catch (exception\WorkflowException $e) {
                if (strpos($e->getMessage(), '乐观锁冲突') !== false && $retry < $maxRetries) {
                    $expectedVersion = $this->markingStore->getVersion($subject);
                    Runtime::sleep(rand(50000, 200000) / 1_000_000);
                    continue;
                }
                throw $e;
            }
        }

        $this->markingStore->recordHistory(
            $subject, '__rollback__',
            implode(',', $toPlaces), implode(',', $fromPlaces),
            $context, model\WorkflowHistory::TYPE_ROLLBACK
        );

        if ($this->eventService) {
            $this->eventService->trigger("workflow.{$workflow->getName()}.rollback", [
                'workflow' => $workflow,
                'subject'  => $subject,
                'context'  => $context,
                'from'     => $toPlaces,
                'to'       => $fromPlaces,
            ]);
        }

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
        $this->authorize('__delegate__', $subject, array_merge($context, ['target_user' => $toUserId]));

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
    public function cancelTasks(string $subject, string $transitionName = '', ?int $actorId = null): bool
    {
        $authContext = [];
        if ($actorId !== null) {
            $authContext['user_id'] = $actorId;
        }
        if ($transitionName !== '') {
            $authContext['transition_name'] = $transitionName;
        }

        $this->authorize('__cancel_tasks__', $subject, $authContext);

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


    /**
     * 查找转换（被 dryRun / can / canAny / rollback 使用）
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
     * 版本校验（V2: 不再由 apply 直接调用，保留供外部使用）
     * @deprecated V2 中版本校验已迁移至 VerifyVersionPipe
     */
    protected function checkVersion(Workflow $workflow, string $subject, array $marking): void
    {
        if (empty($marking)) {
            return;
        }
        $defVersion = $workflow->getVersion();
        if ($defVersion <= 0) {
            return;
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

}
