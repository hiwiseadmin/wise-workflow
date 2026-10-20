<?php

declare(strict_types=1);

namespace wise\workflow;

use wise\support\traits\RegistersMigrations;
use wise\support\traits\ServiceTrait;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\contract\PrincipalResolverInterface;
use wise\workflow\contract\SubjectOwnershipInterface;
use wise\workflow\store\DatabaseMarkingStore;
use wise\workflow\guard\ExpressionGuard;
use wise\workflow\task\TaskManager;
use wise\workflow\listener\TaskCompletionListener;
use wise\workflow\listener\DatabaseNotificationListener;

/**
 * Wise-Workflow 服务注册（V2: Pipeline 架构升级 + 延迟加载）
 *
 * 使用 DeferredService：register() 仅绑定闭包工厂，
 * 配置合并与核心绑定在首次 make() 时触发。
 *
 * boot() 负责事件注入和命令注册（每个请求都执行）。
 */
class WorkflowServiceProvider extends \think\Service
{
    use ServiceTrait;

    use \wise\support\traits\RegistersMigrations;

    /**
     * 提供的服务映射
     */
    /**
     * 实际注册逻辑（首次 make 时执行）
     */
    public function register(): void
    {
        $this->mergeConfig('wise-workflow');

        $this->app->bind(MarkingStoreInterface::class, DatabaseMarkingStore::class);
        $this->app->bind(ExpressionGuard::class, ExpressionGuard::class);

        // 守卫上下文白名单（安全审计阶段 2 / B-3）：`setAllowedContextKeys()` 此前
        // 在生产代码中从未被调用，白名单形同虚设。这里按配置装配：默认只放行包自身的键，
        // 业务需在 wise-workflow.expression.allowed_context_keys 中显式补充自己的键
        // （置 null 可退回「不限制」的旧行为）。
        $allowedContextKeys = config('wise-workflow.expression.allowed_context_keys', null);
        ExpressionGuard::setAllowedContextKeys($allowedContextKeys === null ? null : (array) $allowedContextKeys);

        // 安全默认（审计阶段 1 / B-1）：未绑定 permission 时提供兜底实现，
        // 使「没有配置权限系统」等于**拒绝**，而不是静默放行。
        // 业务绑定自己的 PermissionServiceInterface 实现即可覆盖本兜底；
        // 确实不需要权限系统的部署请显式设置 wise-workflow.permission.allow_all = true。
        if (!$this->app->has('permission')) {
            $this->app->bind(
                'permission',
                config('wise-workflow.permission.allow_all', false)
                    ? \wise\workflow\permission\AllowAllPermission::class
                    : \wise\workflow\permission\DenyAllPermission::class
            );
        }

        // 可信主体（安全审计阶段 3 / C-1）：未绑定时默认用会话解析器，使
        // context.user_id 具备可信来源；使用 JWT / 网关注入身份的项目请绑定
        // 自己的 PrincipalResolverInterface 实现（本绑定会被宿主绑定覆盖）。
        if (!$this->app->has(PrincipalResolverInterface::class)) {
            $this->app->bind(PrincipalResolverInterface::class, support\SessionPrincipalResolver::class);
        }

        // 绑定任务管理器，并自动注入事件服务。
        // 必须在此闭包中注入，而非在 register 顶部对一次性实例 setEventService，
        // 否则 HTTP 等路径中通过容器 make 出的 TaskManager 仍会缺失 eventService，
        // 导致并行审批完成后无法驱动状态流转。
        $this->app->bind(TaskManager::class, function () {
            $taskManager = new TaskManager();
            try {
                $eventService = $this->app->has('think\Event')
                    ? $this->app->make('think\Event')
                    : ($this->app->has('events') ? $this->app->make('events') : null);
                if ($eventService) {
                    $taskManager->setEventService($eventService);
                }
            } catch (\Throwable $e) {
                // 事件服务不可用时静默回退
            }
            return $taskManager;
        });

        $this->app->bind(TransitionPipeline::class, function () {
            $taskManager = null;
            try {
                $taskManager = $this->app->make(TaskManager::class);
            } catch (\Throwable $e) {
                // TaskManager 不可用时静默回退
            }

            return new TransitionPipeline(
                $this->app->make(MarkingStoreInterface::class),
                null,  // eventService — 由 Engine 层面注入
                null,  // permissionService
                null,  // historyModel
                $taskManager
            );
        });

        // 绑定完整装配的工作流引擎：注入事件服务、权限服务与任务管理器，
        // 供包内 Facade 与 TaskCompletionListener 使用，
        // 避免开箱即用时降级为"无事件 / 无权限 / 无任务"状态。
        $this->app->bind(WorkflowEngine::class, function () {
            $markingStore = $this->app->make(MarkingStoreInterface::class);

            $eventService = $this->app->has('think\Event')
                ? $this->app->make('think\Event')
                : ($this->app->has('events') ? $this->app->make('events') : null);

            $permissionService = $this->app->has('permission')
                ? $this->app->make('permission')
                : null;

            $taskManager = $this->app->make(TaskManager::class);

            $engine = new WorkflowEngine(
                $markingStore,
                $eventService,
                $permissionService,
                null,
                $taskManager
            );

            // 可信主体与实例归属（安全审计阶段 3 / C-1）
            try {
                $engine->setPrincipalResolver($this->app->make(PrincipalResolverInterface::class));
            } catch (\Throwable $e) {
                // 主体解析不可用：保持未注入（context.user_id 会被剥离）
            }

            try {
                if ($this->app->has(SubjectOwnershipInterface::class)) {
                    $engine->setOwnershipService($this->app->make(SubjectOwnershipInterface::class));
                }
            } catch (\Throwable $e) {
                // 归属服务不可用：保持未注入（不做归属拦截）
            }

            return $engine;
        });

        // 注册 CLI 命令（仅在真正执行 php think ... 时触发）
        $this->commands([
            command\SlaCheck::class,
        ]);
    }

    /**
     * 启动服务（事件注入 + 命令注册）
     */
    public function boot(): void
    {
        $this->registerMigrations();

        // 注册 MCP 能力提供者（需安装 wiseadmin/wise-mcp）
        $this->registerMcpProvider();

        // 注册 Web 路由（配置驱动，enable=false 时不注册；无条件注册以兼容 Swoole）
        RouteRegistrar::register();

        // 开箱即用事件监听接线（并行审批自动流转 + 通知）
        $this->registerEventListeners();
    }

    /**
     * 注册工作流事件监听器，使并行审批完成后能自动驱动状态流转、
     * 配置开启时发出工作流通知。
     *
     * 使用与 TaskManager / WorkflowEngine 相同的 think\Event 单例，确保触发与监听贯通。
     * 每次进程引导仅注册一次（事件服务为单例，FPM 每请求新进程、Swoole 常驻仅一次），不会重复注册。
     */
    protected function registerEventListeners(): void
    {
        $eventService = null;
        try {
            $eventService = $this->app->has('think\Event')
                ? $this->app->make('think\Event')
                : ($this->app->has('events') ? $this->app->make('events') : null);
        } catch (\Throwable $e) {
            // 事件服务不可用时跳过监听注册（不影响主流程）
            return;
        }

        if (!$eventService) {
            return;
        }

        // 1. 并行审批完成 → 自动执行状态转换
        //    与 TaskManager 触发 'workflow.task_completed' 使用同一事件实例，闭环成立。
        try {
            $engine = $this->app->make(WorkflowEngine::class);
            $eventService->listen('workflow.task_completed', [new TaskCompletionListener($engine), 'handle']);
        } catch (\Throwable $e) {
            error_log('[wise-workflow] 注册 TaskCompletionListener 失败: ' . $e->getMessage());
        }

        // 2. 工作流事件通知（受 notification.enabled 开关控制）
        $notificationEnabled = $this->app->config->get('wise-workflow.notification.enabled', false);
        if ($notificationEnabled) {
            try {
                $eventService->listen('workflow.*', [new DatabaseNotificationListener(), 'handle']);
            } catch (\Throwable $e) {
                error_log('[wise-workflow] 注册 NotificationListener 失败: ' . $e->getMessage());
            }
        }
    }

    /**
     * 将工作流能力以 MCP 工具暴露（可选集成）
     *
     * 仅当 wiseadmin/wise-mcp 已安装时生效；未安装时静默跳过。
     */
    protected function registerMcpProvider(): void
    {
        if (!interface_exists(\wise\mcp\contract\ToolProviderInterface::class)) {
            return;
        }
        if (!class_exists(\wise\support\ExtPoint::class) || !interface_exists(\wise\mcp\contract\ToolProviderInterface::class)) {
            return;
        }

        try {
            \wise\support\ExtPoint::register(
                \wise\mcp\contract\ToolProviderInterface::EXT_POINT,
                'workflow',
                \wise\workflow\mcp\WorkflowToolProvider::class
            );
        } catch (\Throwable $e) {
            // MCP 为可选能力，注册失败不影响工作流本身
            error_log('[wise-workflow] 注册 MCP 提供者失败: ' . $e->getMessage());
        }
    }

}
