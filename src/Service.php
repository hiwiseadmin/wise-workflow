<?php

declare(strict_types=1);

namespace wise\workflow;

use wise\workflow\trait\ServiceTrait;

/**
 * Wise-Workflow 服务注册
 * 
 * 注册：
 * - 配置合并
 * - MarkingStoreInterface → DatabaseMarkingStore 接口绑定
 * - TaskManager 和 ExpressionGuard 单例
 * - console 命令：publish / sla-check
 */
class Service extends \think\Service
{
    use ServiceTrait;

    /**
     * 注册服务
     */
    public function register(): void
    {
        $this->mergeConfig('wise-workflow');

        // 接口绑定
        $this->app->bind(MarkingStoreInterface::class, DatabaseMarkingStore::class);

        // 注册 ExpressionGuard（单例，方便表达式引擎缓存）
        $this->app->bind(ExpressionGuard::class, ExpressionGuard::class);

        // 注册 TaskManager（单例，Phase 3a 并行审批基础设施）
        $this->app->bind(TaskManager::class, TaskManager::class);
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                command\Publish::class,
                command\SlaCheck::class,
            ]);
        }
    }
}
