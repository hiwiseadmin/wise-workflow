<?php
declare(strict_types=1);

namespace wise\workflow\permission;

use wise\workflow\contract\PermissionServiceInterface;
use wise\workflow\model\Transition;

/**
 * 放行一切转换的权限服务（**仅供显式选择「不要权限系统」的部署使用**）
 *
 * 触发条件：`wise-workflow.permission.allow_all = true`，或业务自行绑定本类。
 * 它等价于关闭权限校验，请勿在需要授权的工作流上使用
 * （安全审计阶段 1 / B-1：默认必须是拒绝，放行必须是显式决定）。
 *
 * @package wise\workflow\permission
 */
class AllowAllPermission implements PermissionServiceInterface
{
    public function check(Transition $transition, array $context): bool
    {
        return true;
    }
}
