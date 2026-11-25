<?php
declare(strict_types=1);

namespace wise\workflow\permission;

use wise\workflow\contract\PermissionServiceInterface;
use wise\workflow\model\Transition;

/**
 * 拒绝一切转换的权限服务（安全默认实现）
 *
 * 用途：作为容器标识 `permission` 的兜底绑定——「没有配置权限系统」应当等于
 * **拒绝**，而不是静默放行（安全审计阶段 1 / B-1）。
 *
 * 业务应绑定自己的实现（实现 PermissionServiceInterface）覆盖本兜底；
 * 确实不需要权限系统的部署请显式设置 `wise-workflow.permission.allow_all = true`。
 *
 * @package wise\workflow\permission
 */
class DenyAllPermission implements PermissionServiceInterface
{
    public function check(Transition $transition, array $context): bool
    {
        return false;
    }
}
