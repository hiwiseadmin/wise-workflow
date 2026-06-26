<?php
declare(strict_types=1);

namespace wise\workflow;

use think\facade\Session;

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
        if (!$this->permissionService) {
            return true; // 无权限服务时默认放行
        }

        $transitionName = $context['transition_name'] ?? '';
        $workflowName   = $context['workflow_name'] ?? '';

        if (!$transitionName || !$workflowName) {
            return true;
        }

        $permissionKey = "workflow.{$workflowName}.{$transitionName}";
        $userId = Session::get('user.id');

        if (!$userId) {
            return false;
        }

        return $this->permissionService->check($userId, $permissionKey);
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
