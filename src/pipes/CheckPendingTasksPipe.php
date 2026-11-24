<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\exception\WorkflowException;

/**
 * 并行任务检查管道（Check Pending Tasks Pipe）
 *
 * 在执行转换前检查是否存在未完成的并行审批任务。
 * 如果有未完成的任务，直接拦截，要求通过 TaskManager::completeTask 完成审批。
 */
class CheckPendingTasksPipe implements TransitionPipeInterface
{
    /**
     * 管道引用（由 TransitionPipeline::resolvePipeInstance 自动注入）
     * @var TransitionPipeline
     */
    protected $pipeline;

    public function __construct(TransitionPipeline $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        $taskManager = $this->pipeline->getTaskManager();

        if (!$taskManager) {
            $payload->log(__CLASS__, 'skipped', '未注入 TaskManager');
            return $next($payload);
        }

        $pendingTasks = $taskManager->getPendingTasksForTransition(
            $payload->subject,
            $payload->transitionName
        );

        if (!empty($pendingTasks)) {
            throw new WorkflowException(
                "转换 [{$payload->transitionName}] 存在未完成的并行审批任务，请通过 TaskManager::completeTask 完成审批"
            );
        }

        $payload->log(__CLASS__, 'passed', '无未完成并行任务');
        return $next($payload);
    }
}
