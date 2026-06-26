<?php
declare(strict_types=1);

namespace wise\workflow\facade;

use think\Facade;

/**
 * 任务管理器门面（Task Manager Facade）
 * 
 * @package wise\workflow\facade
 * 
 * @method static array createTasks(\wise\workflow\Workflow $workflow, string $subject, string $transitionName, array $assignees, string $policy, mixed $policyValue, array $context)
 * @method static bool  completeTask(string $subject, string $transitionName, string $taskKey, int $userId, string $result, array $context)
 * @method static array getPendingTasks(string $subject)
 * @method static array getUserTasks(int $userId, string $status)
 * @method static array getPendingTasksForTransition(string $subject, string $transitionName)
 * @method static bool  cancelTasks(string $subject, string $transitionName)
 * @method static bool  delegateTask(string $subject, string $transitionName, string $taskKey, int $fromUserId, int $toUserId)
 * @method static void  setEngine(\wise\workflow\WorkflowEngine $engine)
 */
class TaskManager extends Facade
{
    protected static function getFacadeClass()
    {
        return \wise\workflow\TaskManager::class;
    }
}
