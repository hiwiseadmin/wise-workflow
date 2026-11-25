<?php
declare(strict_types=1);

namespace wise\workflow\facade;

use think\Facade;

/**
 * 工作流引擎门面（Workflow Engine Facade）
 *
 * 提供静态调用方式访问 WorkflowEngine 的所有方法
 *
 * @package wise\workflow\facade
 *
 * @method static array  getMarking(string $subject)
 * @method static void   setMarking(string $subject, array $marking, array $context = [])
 * @method static void   init(\wise\workflow\Workflow $workflow, string $subject, array $context = [])
 * @method static array  getHistory(string $subject, int $limit = 50)
 * @method static array  getEnabledTransitions(\wise\workflow\Workflow $workflow, string $subject)
 * @method static array  canAny(\wise\workflow\Workflow $workflow, string $subject, array $context = [])
 * @method static bool   can(\wise\workflow\Workflow $workflow, string $subject, string $transitionName)
 * @method static array  apply(\wise\workflow\Workflow $workflow, string $subject, string $transitionName, array $context = [])
 * @method static array  dryRun(\wise\workflow\Workflow $workflow, string $subject, string $transitionName, array $context = [])
 * @method static array  rollback(\wise\workflow\Workflow $workflow, string $subject, array $context = [])
 * @method static void   delegate(\wise\workflow\Workflow $workflow, string $subject, int $toUserId, array $context = [])
 */
class Workflow extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\workflow\WorkflowEngine::class;
    }
}
