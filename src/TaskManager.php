<?php
declare(strict_types=1);

namespace wise\workflow;

use wise\workflow\model\WorkflowTask;
use wise\workflow\exception\WorkflowException;

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
     * 工作流引擎（用于完成审批后自动执行 apply）
     * @var WorkflowEngine|null
     */
    protected $engine;

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
        $this->taskModel->where('id', $task->id)->update([
            'status'       => $status,
            'result'       => $result,
            'comment'      => $context['comment'] ?? '',
            'assignee'     => $userId, // 记录实际完成人
            'completed_at' => $now,
            'update_time'   => $now,
        ]);

        // 判断是否需要自动流转
        $shouldTransition = $this->shouldTransition($subject, $transitionName, $result);

        if ($shouldTransition && $this->engine) {
            $workflow = WorkflowRegistry::get(explode(':', $subject, 2)[0]);
            $context['remark'] = $context['comment'] ?? '审批完成';
            $this->engine->apply($workflow, $subject, $transitionName, $context);
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

        $tasks = $query->select();
        foreach ($tasks as $task) {
            $task->status = WorkflowTask::STATUS_CANCELLED;
            $task->update_time = date('Y-m-d H:i:s');
            $task->save();
        }

        return count($tasks) > 0;
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
