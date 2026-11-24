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

        $hasHistory = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->count() > 0;

        $round = $hasHistory
            ? '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 4)
            : '';

        $this->taskModel->startTrans();

        try {
            foreach ($assignees as $index => $userId) {
                $taskKey = "{$transitionName}{$round}_" . ($index + 1);
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

            $this->taskModel->commit();
        } catch (\Throwable $e) {
            $this->taskModel->rollback();
            throw $e;
        }

        return $tasks;
    }


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

        $status = $result === 'rejected' ? WorkflowTask::STATUS_REJECTED : WorkflowTask::STATUS_COMPLETED;
        $updateData = [
            'status'       => $status,
            'result'       => $result,
            'comment'      => $context['comment'] ?? '',
            'completed_at' => $now,
            'update_time'  => $now,
        ];

        if ($userId <= 0 || $userId !== (int)$task->assignee) {
            throw new WorkflowException("无权处理该任务: [{$taskKey}]（仅任务分配人可完成）");
        }

        $this->taskModel->startTrans();

        try {
            $affected = $this->taskModel
                ->where('id', $task->id)
                ->where('status', WorkflowTask::STATUS_PENDING)
                ->update($updateData);

            if ($affected === 0) {
                throw new WorkflowException("任务 [{$taskKey}] 已被其他人处理，请刷新重试");
            }

            $shouldTransition = $this->shouldTransition($subject, $transitionName, $result, (string) $task->task_key);

            if ($shouldTransition) {
                if ($this->eventService) {
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
                    $parts = explode(':', $subject, 2);
                    $workflowName = $parts[0] ?? '';

                    if (empty($workflowName) || !WorkflowRegistry::has($workflowName)) {
                        $this->taskModel->where('id', $task->id)
                            ->update(['status' => WorkflowTask::STATUS_PENDING, 'update_time' => $now]);
                        throw new WorkflowException("无法从 subject [{$subject}] 解析工作流名称");
                    }

                    $workflow = WorkflowRegistry::get($workflowName);
                    if (!isset($context['remark'])) {
                        $context['remark'] = $context['comment'] ?? '审批完成';
                    }

                    try {
                        $this->engine->apply($workflow, $subject, $transitionName, $context);
                    } catch (\Throwable $e) {
                        $this->taskModel->where('id', $task->id)
                            ->update(['status' => WorkflowTask::STATUS_PENDING, 'update_time' => $now]);
                        throw $e;
                    }
                } else {
                    throw new WorkflowException("任务审批完成但无法执行状态转换：事件服务和引擎均未注入");
                }
            }

            $this->taskModel->commit();

            return $shouldTransition;
        } catch (\Throwable $e) {
            $this->taskModel->rollback();
            throw $e;
        }
    }


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

        if ($fromUserId <= 0 || $fromUserId !== (int)$task->assignee) {
            throw new WorkflowException("无权转交该任务: [{$taskKey}]（仅任务分配人可转交）");
        }

        $task->status  = WorkflowTask::STATUS_DELEGATED;
        $task->update_time = date('Y-m-d H:i:s');
        $task->save();

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


    /**
     * 判断是否应该触发状态转换
     *
     * @param string $subject
     * @param string $transitionName
     * @param string $result 当前任务的处理结果
     * @return bool
     */
    protected function shouldTransition(
        string $subject,
        string $transitionName,
        string $result,
        ?string $taskKey = null
    ): bool {
        if ($result === 'rejected') {
            return true;
        }

        $allTasks = $this->taskModel
            ->where('subject', $subject)
            ->where('transition_name', $transitionName)
            ->select();

        if ($allTasks->isEmpty()) {
            return false;
        }

        $roundPrefix = ($taskKey !== null && $taskKey !== '') ? $this->roundPrefix($taskKey) : null;

        $policy      = $allTasks[0]->policy ?? WorkflowTask::POLICY_ALL;
        $policyValue = $allTasks[0]->policy_value ?? null;

        $total     = 0;
        $completed = 0;
        $rejected  = 0;

        foreach ($allTasks as $t) {
            if ($roundPrefix !== null && strpos((string) $t->task_key, $roundPrefix) !== 0) {
                continue;
            }

            if ($t->status === WorkflowTask::STATUS_COMPLETED) {
                $completed++;
                $total++;
            } elseif ($t->status === WorkflowTask::STATUS_REJECTED) {
                $rejected++;
                $total++;
            } elseif ($t->status === WorkflowTask::STATUS_PENDING) {
                $total++;
            }
        }

        if ($total === 0) {
            return false;
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

    /**
     * 从任务 key 推导审批轮次前缀（"{transition}_{round}_"）
     *
     * createTasks() 生成的 key 形如 "{transition}_{round}_{index}"；
     * 委托生成的 key 形如 "{origin}_d_{His}"，需剥掉委托后缀后推导，
     * 以保证同一轮次内的任务落在同一个统计范围内。
     */
    protected function roundPrefix(string $taskKey): string
    {
        $key = preg_replace('/_d_\d{6}$/', '', $taskKey) ?? $taskKey;
        $pos = strrpos($key, '_');

        return $pos === false ? $key : substr($key, 0, $pos + 1);
    }
}
