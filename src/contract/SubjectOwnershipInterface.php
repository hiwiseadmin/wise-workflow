<?php
declare(strict_types=1);

namespace wise\workflow\contract;

/**
 * 实例归属判定契约（安全审计阶段 3 / C-1，可选）
 *
 * 包无法替宿主判断「order:1001 属于谁」——归属规则是业务事实。宿主实现本接口并
 * 绑定到容器后，引擎会在**状态写入**前校验归属；控制器读路径也会调用引擎的
 * `checkSubjectAccess()`。未绑定时该项校验不生效（保持既有行为，不做隐式拦截）。
 *
 * 拒绝时的行为是抛出 `WorkflowException`（HTTP 层映射为业务错误响应），
 * 而不是静默通过。
 *
 * @package wise\workflow\contract
 */
interface SubjectOwnershipInterface
{
    /**
     * 判断实例是否归属该主体
     *
     * @param string $subject 实例标识，例如 order:1001
     * @param int    $userId  可信主体 ID（来自 PrincipalResolverInterface，恒 > 0）
     * @param array  $context 上下文（含 subject / user_id 等）
     * @return bool 归属返回 true，否则返回 false
     */
    public function isOwnedBy(string $subject, int $userId, array $context = []): bool;
}
