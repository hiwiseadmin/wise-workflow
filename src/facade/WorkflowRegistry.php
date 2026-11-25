<?php
declare(strict_types=1);

namespace wise\workflow\facade;

use think\Facade;

/**
 * 工作流注册表门面（Workflow Registry Facade）
 *
 * @package wise\workflow\facade
 *
 * @method static \wise\workflow\Workflow  get(string $name)
 * @method static array                    all()
 * @method static bool                     has(string $name)
 * @method static void                     add(\wise\workflow\Workflow $workflow)
 * @method static void                     clear()
 * @method static void                     loadFromDatabase()
 * @method static bool                     clearCache()
 * @method static \wise\workflow\guard\ExpressionGuard getExpressionGuard()
 * @method static callable|null            buildGuard(\wise\workflow\model\Transition $transition)
 */
class WorkflowRegistry extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\workflow\WorkflowRegistry::class;
    }
}
