<?php
declare(strict_types=1);

namespace wise\workflow\contract;

use wise\workflow\model\Transition;

/**
 * 权限服务接口（Permission Service Interface）
 *
 * 工作流包内所有权限检查（CheckPermissionPipe、GuardListener）统一调用本契约：
 *     check(Transition $transition, array $context): bool
 *
 * 业务层应实现此接口，从 $context 中自行提取当前用户标识（如 user_id），
 * 并判断其是否具备执行该转换的权限。
 *
 * 兼容说明：未实现本接口的旧实现（基于 check($userId, $permissionKey) 风格）
 * 由 GuardListener 自动回退调用，不影响既有业务。
 *
 * @package wise\workflow\contract
 */
interface PermissionServiceInterface
{
    /**
     * 检查是否有权限执行指定转换
     *
     * @param Transition $transition 转换对象
     * @param array      $context    上下文，可包含 user_id、auto_sla 等键
     * @return bool 允许返回 true，拒绝返回 false
     */
    public function check(Transition $transition, array $context): bool;
}
