<?php
declare(strict_types=1);

namespace wise\workflow\listener;

use think\facade\Session;
use wise\workflow\contract\PermissionServiceInterface;
use wise\workflow\model\Transition;
use wise\workflow\WorkflowRegistry;

/**
 * 权限守卫监听器（Guard Listener）
 *
 * 用于工作流转换的权限检查
 * 可作为 Transition 的 guard callable 使用
 *
 * 功能：
 * - 检查当前用户是否登录
 * - 检查当前用户是否有权限执行该转换
 *
 * 使用示例：
 * <pre>
 * $guard = GuardListener::create($permissionService);
 * $transition->setGuard($guard);
 * </pre>
 *
 * @package wise\workflow
 */
class GuardListener
{
    /**
     * 权限服务对象
     *
     * 用于检查用户是否有权限执行某个操作
     *
     * @var mixed
     */
    protected $permissionService;

    /**
     * 构造函数
     *
     * @param mixed $permissionService 权限服务对象（可选），如果为 null 则不进行权限检查
     */
    public function __construct($permissionService = null)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * 权限守卫：检查当前用户是否有权限执行该转换
     *
     * 此方法可作为 Transition 的 guard callable 使用
     *
     * 检查逻辑：
     * 1. 如果没有配置权限服务，默认放行（返回 true）
     * 2. 从上下文获取工作流名称和转换名称
     * 3. 如果没有工作流名称或转换名称，默认放行
     * 4. 检查用户是否登录（从 Session 获取 user.id）
     * 5. 调用权限服务检查用户是否有权限
     *
     * @param array $marking 当前标记（状态节点集合）
     * @param array $context 上下文信息，必须包含：
     *                      - 'transition_name'：转换名称
     *                      - 'workflow_name'：工作流名称
     * @return bool 如果有权限返回 true，否则返回 false
     */
    public function __invoke(array $marking, array $context = []): bool
    {
        if (!empty($context['auto_sla'] ?? false)) {
            return true;
        }

        if (!$this->permissionService) {
            return true;
        }

        $transitionName = $context['transition_name'] ?? '';
        $workflowName   = $context['workflow_name'] ?? '';

        if (!$transitionName || !$workflowName) {
            return true;
        }

        if ($this->permissionService instanceof PermissionServiceInterface) {
            $transition = $this->resolveTransition($workflowName, $transitionName)
                ?? new Transition($transitionName, [], []);
            return (bool) $this->permissionService->check($transition, $this->withUserId($context));
        }

        $permissionKey = "workflow.{$workflowName}.{$transitionName}";

        $sessionKey = config('wise-workflow.user_session_key', 'user.id');
        try {
            $userId = Session::get($sessionKey);
        } catch (\Throwable $e) {
            $userId = null;
        }

        if (!$userId) {
            return false;
        }

        return (bool) $this->permissionService->check($userId, $permissionKey);
    }

    /**
     * 从工作流注册表解析指定转换对象
     */
    protected function resolveTransition(string $workflowName, string $transitionName): ?Transition
    {
        try {
            $workflow = WorkflowRegistry::get($workflowName);
            foreach ($workflow->getTransitions() as $transition) {
                if ($transition->getName() === $transitionName) {
                    return $transition;
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * 将当前登录用户 ID 并入上下文，供新契约业务从 context 提取用户
     */
    protected function withUserId(array $context): array
    {
        if (array_key_exists('user_id', $context)) {
            return $context;
        }
        $sessionKey = config('wise-workflow.user_session_key', 'user.id');
        try {
            $userId = Session::get($sessionKey);
        } catch (\Throwable $e) {
            $userId = null;
        }
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }
        return $context;
    }

    /**
     * 创建守卫 callable（用于注册到 Transition）
     *
     * 工厂方法，创建一个闭包函数作为守卫条件
     *
     * @param object|null $permissionService 权限服务对象（可选）
     * @return callable 守卫条件回调函数
     *                  函数签名：function(array $marking, array $context): bool
     */
    public static function create(?object $permissionService = null): callable
    {
        $listener = new static($permissionService);
        return function ($marking, $context) use ($listener) {
            return $listener($marking, $context);
        };
    }
}
