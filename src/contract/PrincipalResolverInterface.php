<?php
declare(strict_types=1);

namespace wise\workflow\contract;

/**
 * 可信主体解析契约（安全审计阶段 3 / C-1）
 *
 * 授权上下文里的 `user_id` 必须来自**认证主体**，而不是请求体。此前调用方可以在
 * `POST context` 里自带 `user_id`，而 `GuardListener::withUserId()` 见到该键就直接采用，
 * 于是「伪造 user_id → 满足守卫/权限条件」成立。
 *
 * 宿主注入本接口实现后：
 * - 引擎在 `apply()` 中**剥离**调用方传入的 `user_id`，填入这里返回的可信值；
 * - 未注入实现时 `user_id` 一律被剥离（不保留调用方值，fail-closed）。
 *
 * 包内默认实现为 `wise\workflow\support\SessionPrincipalResolver`（读会话）；
 * 使用 JWT / 网关注入身份的项目请绑定自己的实现。
 *
 * @package wise\workflow\contract
 */
interface PrincipalResolverInterface
{
    /**
     * 当前认证用户 ID；匿名或无法确定时返回 0
     */
    public function getUserId(): int;
}
