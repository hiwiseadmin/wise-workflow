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

    use RegistersMigrations;

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

        $allowedContextKeys = config('wise-workflow.expression.allowed_context_keys', null);
        ExpressionGuard::setAllowedContextKeys($allowedContextKeys === null ? null : (array) $allowedContextKeys);

        if (!$this->app->has('permission')) {
            $this->app->bind(
                'permission',
                config('wise-workflow.permission.allow_all', false)
                    ? \wise\workflow\permission\AllowAllPermission::class
                    : \wise\workflow\permission\DenyAllPermission::class
            );
        }

        if (!$this->app->has(PrincipalResolverInterface::class)) {
            $this->app->bind(PrincipalResolverInterface::class, support\SessionPrincipalResolver::class);
        }

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
            }
            return $taskManager;
        });

        $this->app->bind(TransitionPipeline::class, function () {
            $taskManager = null;
            try {
                $taskManager = $this->app->make(TaskManager::class);
            } catch (\Throwable $e) {
            }

            return new TransitionPipeline(
                $this->app->make(MarkingStoreInterface::class),
                null,
                null,
                null,
                $taskManager
            );
        });

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

            try {
                $engine->setPrincipalResolver($this->app->make(PrincipalResolverInterface::class));
            } catch (\Throwable $e) {
            }

            try {
                if ($this->app->has(SubjectOwnershipInterface::class)) {
                    $engine->setOwnershipService($this->app->make(SubjectOwnershipInterface::class));
                }
            } catch (\Throwable $e) {
            }

            return $engine;
        });

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

        $this->registerMcpProvider();

        RouteRegistrar::register();

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
            return;
        }

        if (!$eventService) {
            return;
        }

        try {
            $engine = $this->app->make(WorkflowEngine::class);
            $eventService->listen('workflow.task_completed', [new TaskCompletionListener($engine), 'handle']);
        } catch (\Throwable $e) {
            error_log('[wise-workflow] 注册 TaskCompletionListener 失败: ' . $e->getMessage());
        }

        $notificationEnabled = $this->app->config->get('wise-workflow.notification.enabled', false);
        if ($notificationEnabled) {
            if (!class_exists(DatabaseNotificationListener::class)) {
                error_log(
                    '[wise-workflow] 通知监听器类不可用（自动加载失败或包文件缺失）：'
                    . 'notification.enabled=true 但通知不会发送'
                );
            } else {
                try {
                    $eventService->listen('workflow.*', [new DatabaseNotificationListener(), 'handle']);
                } catch (\Throwable $e) {
                    error_log('[wise-workflow] 注册 NotificationListener 失败: ' . $e->getMessage());
                }
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
            error_log('[wise-workflow] 注册 MCP 提供者失败: ' . $e->getMessage());
        }
    }

}
