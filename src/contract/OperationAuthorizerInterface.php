<?php
declare(strict_types=1);

namespace wise\workflow\contract;

/**
 * 操作级授权契约（可选实现，安全审计阶段 2 / D-1、D-2）
 *
 * 管道内的转换授权使用 `PermissionServiceInterface::check()`（以 Transition 为维度），
 * 而 `rollback` / `delegate` / `cancelTasks` 并不是「转换」，无法自然地用 Transition 表达。
 *
 * 权限服务**可选**实现本接口，以便按操作区分授权：
 * - 实现本接口：引擎调用 `authorizeOperation()`；
 * - 未实现：引擎退化为「用合成的 Transition 调用 check()」，
 *   这样既有权限实现无需任何改动即可对这些操作生效（不强制升级）。
 *
 * @package wise\workflow\contract
 */
interface OperationAuthorizerInterface
{
    /**
     * 授权一个非转换类操作
     *
     * @param string $operation 操作名：`__rollback__` / `__delegate__` / `__cancel_tasks__`
     * @param string $subject   工作流实例标识，例如 order:1001
     * @param array  $context   上下文（含 user_id、subject、remark/target_user 等）
     * @return bool 允许返回 true，拒绝返回 false
     */
    public function authorizeOperation(string $operation, string $subject, array $context): bool;
}
