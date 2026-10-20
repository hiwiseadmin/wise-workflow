<?php
declare(strict_types=1);

namespace wise\workflow\task;

use wise\workflow\model\WorkflowTask;
use wise\workflow\exception\WorkflowException;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\event\TaskCompletedEvent;

/**
 * 并行审批任务管理器（Task Manager）
 * 
 * 为工作流提供会签/或签/比例签/数量签等高级审批模式
 * 完全独立于 WorkflowEngine，不侵入 apply/can/init 方法
 * 
 * 审批策略：
 * - all:   全部审批通过 → 自动调用 Engine::apply
 * - any:   任一人审批 → 立即调用 Engine::apply
 * - ratio: 通过比例达标 → 调用 Engine::apply
 * - count: 通过人数达标 → 调用 Engine::apply
 * 
 * 使用流程：
 * <pre>
 * // 1. 创建工作流实例
 * $engine->init($workflow, 'order:123');
 * 
 * // 2. 创建并行审批任务
 * $taskManager->createTasks($workflow, 'order:123', 'approve', [101, 102, 103], 'all');
 * 
 * // 3. 各审批人完成任务
 * $completed = $taskManager->completeTask('order:123', 'approve', 'approve_1', 101, 'approved');
 * if ($completed) {
 *     // 会签完成，已自动 apply
 * }
 * </pre>
 * 
 * @package wise\workflow
 */
class TaskManager
{
    /**
     * 任务模型
     * @var WorkflowTask
     */
    protected $taskModel;

    /**
     * 工作流引擎（V2: 不再直接调用，改为事件驱动）
     * @var WorkflowEngine|null
     */
    protected $engine;

    /**
     * V2: 事件服务（用于发出 TaskCompletedEvent）
     * @var object|null
     */
    protected $eventService;

    /**
     * 构造函数
     * 
     * @param WorkflowTask|null    $taskModel
     * @param WorkflowEngine|null  $engine
     */
    public function __construct(?WorkflowTask $taskModel = null, ?WorkflowEngine $engine = null)
    {
        $this->taskModel = $taskModel ?: new WorkflowTask();
        $this->engine    = $engine;
    }

    /**
     * 设置工作流引擎
     */
    public function setEngine(WorkflowEngine $engine): void
    {
        $this->engine = $engine;
    }

    /**
     * V2: 设置事件服务
     *
     * @param object $eventService 必须实现 trigger(string, array): void
     */
    public function setEventService($eventService): void
    {
        $this->eventService = $eventService;
    }

    // ────────────────────────────────────────────
    //  任务创建
    // ────────────────────────────────────────────

    /**
     * 为某个转换创建并行审批任务
     * 
     * @param Workflow $workflow       工作流定义
     * @param string   $subject        主体标识
     * @param string   $transitionName 转换名称（后续完成审批后触发）
     * @param int[]    $assignees      处理人用户ID列表
     * @param string   $policy         审批策略：all/any/ratio/count
     * @param mixed    $policyValue    策略参数值
     * @param array    $context        上下文信息
     * @return array  创建的任务列表
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
        // 安全（审计阶段 3 / F-4）：入参收敛——去重、取整、基数上限与策略枚举。
        // 此前 assignees 没有任何上限，一次请求就能按数组基数写入 N 行任务，
        // 且 policy 不校验枚举值。
        $assignees    = array_values(array_unique(array_map('intval', $assignees)));
        $maxAssignees = (int) config('wise-workflow.task.max_assignees', 50);

        if ($assignees === []) {
            throw new WorkflowException("审批人列表不能为空");
        }
        if (count($assignees) > $maxAssignees) {
            throw new WorkflowException("审批人数超出允许范围（最多 {$maxAssignees} 人）");
        }
        if (!in_array($policy, ['all', 'any', 'ratio', 'count'], true)) {
            throw new WorkflowException("不支持的审批策略 [{$policy}]");
        }

        // 检查是否已有进行中的任务
        $existing = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->where('status', 'pending')
            ->find();

        if ($existing) {
            throw new WorkflowException("已存在进行中的审批任务 [{$transitionName}]");
        }

        if (empty($assignees)) {
            throw new WorkflowException("审批人列表不能为空");
        }

        $tasks = [];
        $now   = date('Y-m-d H:i:s');

        foreach ($assignees as $index => $userId) {
            $taskKey = "{$transitionName}_" . ($index + 1);
            $taskData = [
                'subject'         => $subject,
                'transition_name' => $transitionName,
                'task_key'        => $taskKey,
                'assignee'        => (int)$userId,
                'status'          => WorkflowTask::STATUS_PENDING,
                'policy'          => $policy,
                'policy_value'    => $policyValue !== null ? (string)$policyValue : null,
                'create_time'      => $now,
                'update_time'      => $now,
            ];
            $this->taskModel->insert($taskData);
            $tasks[] = $taskData;
        }

        return $tasks;
    }

    // ────────────────────────────────────────────
    //  任务完成
    // ────────────────────────────────────────────

    /**
     * 完成一个审批任务
     * 
     * @param string $subject        主体标识
     * @param string $transitionName 转换名称
     * @param string $taskKey        任务标识
     * @param int    $userId         完成人用户ID
     * @param string $result         处理结果（如 'approved' / 'rejected'）
     * @param array  $context        上下文（含 remark 等）
     * @return bool 是否触发状态转换
     * @throws WorkflowException
     */
    public function completeTask(
        string $subject,
        string $transitionName,
        string $taskKey,
        int $userId,
        string $result,
        array $context = []
    ): bool {
        $task = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->where('task_key', $taskKey)
            ->find();

        if (!$task) {
            throw new WorkflowException("任务不存在: [{$taskKey}]");
        }

        if ($task->status !== WorkflowTask::STATUS_PENDING) {
            throw new WorkflowException("任务 [{$taskKey}] 状态为 [{$task->status}]，不可重复完成");
        }

        $now = date('Y-m-d H:i:s');

        // 更新当前任务状态
        $status = $result === 'rejected' ? WorkflowTask::STATUS_REJECTED : WorkflowTask::STATUS_COMPLETED;
        $updateData = [
            'status'       => $status,
            'result'       => $result,
            'comment'      => $context['comment'] ?? '',
            'completed_at' => $now,
            'update_time'  => $now,
        ];

        // 安全（审计阶段 1 / C-2）：完成人必须是该任务的分配人。
        // 此前完成人取自请求体且不校验归属，任何调用方都能以他人身份完成他人待办，
        // 成功后还会把 assignee 改写成调用方（该改写已移除，避免污染审计信息）。
        if ($userId <= 0 || $userId !== (int)$task->assignee) {
            throw new WorkflowException("无权处理该任务: [{$taskKey}]（仅任务分配人可完成）");
        }

        // 使用条件更新确保仅 pending 状态可被完成，防止并发重复完成
        $affected = $this->taskModel
            ->where('id', $task->id)
            ->where('status', WorkflowTask::STATUS_PENDING)
            ->update($updateData);

        if ($affected === 0) {
            throw new WorkflowException("任务 [{$taskKey}] 已被其他人处理，请刷新重试");
        }

        // 判断是否需要自动流转
        $shouldTransition = $this->shouldTransition($subject, $transitionName, $result);

        if ($shouldTransition) {
            // V2: 优先通过事件驱动执行审批流程
            if ($this->eventService) {
                // 安全（审计阶段 2 / F-2）：事件分支此前没有任何补偿——apply() 失败时
                // 任务行已停在 completed、状态却没流转，且 pending 守卫让重试不可能。
                // 现在与 V1 分支对称：失败即把任务恢复为 pending 再抛出。
                // 说明：框架事件同步触发时补偿即时生效；若事件服务改为异步/队列投递，
                // 需由队列侧的失败重试补偿（见 CHANGELOG）。
                try {
                    $this->eventService->trigger('workflow.task_completed', [
                        new TaskCompletedEvent(
                            $subject, $transitionName, $task->policy ?? 'all', $context
                        ),
                    ]);
                } catch (\Throwable $e) {
                    $this->taskModel->where('id', $task->id)
                        ->update(['status' => WorkflowTask::STATUS_PENDING, 'update_time' => $now]);
                    throw $e;
                }
            } elseif ($this->engine) {
                // V1 fallback: 直接调用 Engine::apply()
                $parts = explode(':', $subject, 2);
                $workflowName = $parts[0] ?? '';

                if (empty($workflowName) || !WorkflowRegistry::has($workflowName)) {
                    // apply 失败时回滚任务状态
                    $this->taskModel->where('id', $task->id)
                        ->update(['status' => WorkflowTask::STATUS_PENDING, 'update_time' => $now]);
                    throw new WorkflowException("无法从 subject [{$subject}] 解析工作流名称");
                }

                $workflow = WorkflowRegistry::get($workflowName);
                // 保留原始 remark，避免覆盖调用方传入的值
                if (!isset($context['remark'])) {
                    $context['remark'] = $context['comment'] ?? '审批完成';
                }

                try {
                    $this->engine->apply($workflow, $subject, $transitionName, $context);
                } catch (\Throwable $e) {
                    // apply 失败时回滚任务状态，保持数据一致性
                    $this->taskModel->where('id', $task->id)
                        ->update(['status' => WorkflowTask::STATUS_PENDING, 'update_time' => $now]);
                    throw $e;
                }
            } else {
                // 既无事件服务也无引擎，无法执行状态转换
                throw new WorkflowException("任务审批完成但无法执行状态转换：事件服务和引擎均未注入");
            }
        }

        return $shouldTransition;
    }

    // ────────────────────────────────────────────
    //  任务查询
    // ────────────────────────────────────────────

    /**
     * 获取待处理任务列表
     */
    public function getPendingTasks(string $subject): array
    {
        return $this->taskModel
            ->where('subject', $subject)
            ->where('status', WorkflowTask::STATUS_PENDING)
            ->select()
            ->toArray();
    }

    /**
     * 获取某用户的任务列表
     */
    public function getUserTasks(int $userId, string $status = ''): array
    {
        $query = $this->taskModel->where('assignee', $userId);
        if ($status) {
            $query->where('status', $status);
        }
        return $query->order('id', 'desc')->select()->toArray();
    }

    /**
     * 获取某个转换的未完成任务
     */
    public function getPendingTasksForTransition(string $subject, string $transitionName): array
    {
        return $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->where('status', WorkflowTask::STATUS_PENDING)
            ->select()
            ->toArray();
    }

    /**
     * 取消待处理任务
     */
    public function cancelTasks(string $subject, string $transitionName = ''): bool
    {
        $query = $this->taskModel
            ->where('subject', $subject)
            ->where('status', WorkflowTask::STATUS_PENDING);

        if ($transitionName) {
            $query->where('transition_name', $transitionName);
        }

        // 先计数再批量更新，避免 N+1 问题
        $count = $query->count();
        if ($count > 0) {
            $query->update([
                'status'      => WorkflowTask::STATUS_CANCELLED,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return $count > 0;
    }

    /**
     * 转交任务
     */
    public function delegateTask(
        string $subject,
        string $transitionName,
        string $taskKey,
        int $fromUserId,
        int $toUserId
    ): bool {
        $task = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->where('task_key', $taskKey)
            ->find();

        if (!$task || $task->status !== WorkflowTask::STATUS_PENDING) {
            return false;
        }

        // 安全（审计阶段 1 / C-3）：$fromUserId 此前在方法体内从未被使用，
        // 转交授权降级为「知道 task_key 即可」。现在要求发起人是该任务的分配人。
        if ($fromUserId <= 0 || $fromUserId !== (int)$task->assignee) {
            throw new WorkflowException("无权转交该任务: [{$taskKey}]（仅任务分配人可转交）");
        }

        $task->status  = WorkflowTask::STATUS_DELEGATED;
        $task->update_time = date('Y-m-d H:i:s');
        $task->save();

        // 创建新的待处理任务
        $newKey = $taskKey . '_d_' . date('His');
        $this->taskModel->insert([
            'subject'         => $subject,
            'transition_name' => $transitionName,
            'task_key'        => $newKey,
            'assignee'        => $toUserId,
            'status'          => WorkflowTask::STATUS_PENDING,
            'policy'          => $task->policy,
            'policy_value'    => $task->policy_value,
            'create_time'      => date('Y-m-d H:i:s'),
            'update_time'      => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    // ────────────────────────────────────────────
    //  策略判断
    // ────────────────────────────────────────────

    /**
     * 判断是否应该触发状态转换
     *
     * @param string $subject
     * @param string $transitionName
     * @param string $result 当前任务的处理结果
     * @return bool
     */
    protected function shouldTransition(string $subject, string $transitionName, string $result): bool
    {
        // 如果有人驳回
        if ($result === 'rejected') {
            return true; // 立即流转（进入驳回路径）
        }

        // 获取该转换的所有任务
        $allTasks = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->select();

        if ($allTasks->isEmpty()) {
            return false;
        }

        $policy      = $allTasks[0]->policy ?? WorkflowTask::POLICY_ALL;
        $policyValue = $allTasks[0]->policy_value ?? null;
        $total       = count($allTasks);

        $completed = 0;
        $rejected  = 0;

        foreach ($allTasks as $t) {
            if ($t->status === WorkflowTask::STATUS_COMPLETED) {
                $completed++;
            } elseif ($t->status === WorkflowTask::STATUS_REJECTED) {
                $rejected++;
            }
        }

        switch ($policy) {
            case WorkflowTask::POLICY_ANY:
                return $completed >= 1;

            case WorkflowTask::POLICY_ALL:
                return $completed >= $total;

            case WorkflowTask::POLICY_COUNT:
                $needCount = (int)$policyValue;
                return $completed >= $needCount;

            case WorkflowTask::POLICY_RATIO:
                $needRatio = (float)$policyValue;
                return ($total > 0) && (($completed / $total) >= $needRatio);

            default:
                return $completed >= $total;
        }
    }
}
