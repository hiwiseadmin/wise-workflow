<?php
declare(strict_types=1);

namespace wise\workflow\support;

use think\facade\Session;
use wise\workflow\contract\PrincipalResolverInterface;

/**
 * 基于会话的默认主体解析（安全审计阶段 3 / C-1）
 *
 * 与控制器的 `currentUserId()` 使用同一个 session 键
 * （`wise-workflow.user_session_key`，默认 `user.id`），保证 HTTP 层与引擎层
 * 看到的是同一个主体。
 *
 * 使用 JWT / 网关注入身份的项目，请绑定自己的 `PrincipalResolverInterface`
 * 实现覆盖本类（本类在容器未绑定时才被装配）。
 *
 * @package wise\workflow\support
 */
class SessionPrincipalResolver implements PrincipalResolverInterface
{
    /**
     * {@inheritdoc}
     */
    public function getUserId(): int
    {
        try {
            $sessionKey = config('wise-workflow.user_session_key', 'user.id');
            return (int) (Session::get($sessionKey) ?: 0);
        } catch (\Throwable $e) {
            // 会话不可用视为匿名（fail-closed），绝不回退到请求参数
            return 0;
        }
    }
}
