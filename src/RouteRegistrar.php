<?php

declare(strict_types=1);

namespace wise\workflow;

use think\facade\Route;
use wise\support\context\ContextualLogger;
use wise\support\router\RouteRegistrar as BaseRouteRegistrar;

/**
 * WiseWorkflow 路由注册器（配置驱动）
 *
 * 由 WorkflowServiceProvider::boot() 调用 RouteRegistrar::register() 注册。
 * 前缀 / 中间件 / 限流通过 config('wise-workflow.web') 配置。
 *
 * 安全说明（审计阶段 1 / A）：
 *   本包的 HTTP 接口包含状态变更操作（apply / rollback / delegate / task/*），
 *   而框架把「认证」设计为各路由组自行声明——**不声明中间件就等于匿名可达**。
 *   因此 web.enable 默认关闭（opt-in），且组启用但未声明任何中间件时会在启动期告警。
 *
 * @package wise\workflow
 */
class RouteRegistrar extends BaseRouteRegistrar
{
    protected string $configKey = 'wise-workflow';

    protected function define(array $config): void
    {
        $middleware = array_merge(
            (array) ($config['middleware'] ?? []),
            (array) (is_array($config['groups']['workflow'] ?? [])
                ? ($config['groups']['workflow']['middleware'] ?? [])
                : [])
        );

        if ($middleware === []) {
            if (!(bool) ($config['allow_anonymous'] ?? false)) {
                $message = '[wise-workflow] /api/workflow/* 未声明任何认证中间件，已拒绝注册该路由组：'
                    . '请配置 web.groups.workflow.middleware，或显式设置 web.allow_anonymous = true'
                    . '（仅当由上游网关统一鉴权时才可开启）。';

                try {
                    ContextualLogger::warning($message, ['workflow', 'security']);
                } catch (\Throwable $e) {
                    error_log($message);
                }

                return;
            }

            $this->warnWhenAnonymous($config);
        }

        $group = $this->group('workflow', function () {
            Route::get('definitions', '\wise\workflow\controller\WorkflowController@definitions');
            Route::get('definitions/<name>', '\wise\workflow\controller\WorkflowController@definition');

            Route::get('<name>/<subject>', '\wise\workflow\controller\WorkflowController@status');
            Route::get('<name>/<subject>/history', '\wise\workflow\controller\WorkflowController@history');
            Route::get('<name>/<subject>/tasks', '\wise\workflow\controller\WorkflowController@tasks');

            Route::post('<name>/<subject>/apply', '\wise\workflow\controller\WorkflowController@apply');
            Route::post('<name>/<subject>/rollback', '\wise\workflow\controller\WorkflowController@rollback');
            Route::post('<name>/<subject>/delegate', '\wise\workflow\controller\WorkflowController@delegate');

            Route::post('<name>/<subject>/task/create', '\wise\workflow\controller\WorkflowController@createTasks');
            Route::post('<name>/<subject>/task/<key>/complete', '\wise\workflow\controller\WorkflowController@completeTask');
            Route::post('<name>/<subject>/task/<key>/delegate', '\wise\workflow\controller\WorkflowController@delegateTask');
            Route::post('<name>/<subject>/task/cancel', '\wise\workflow\controller\WorkflowController@cancelTasks');

            Route::get('user-tasks', '\wise\workflow\controller\WorkflowController@userTasks');
        });

        if ($group !== null) {
            $group->allowCrossDomain();
        }
    }

    /**
     * 启动期安全提示：分组已启用但未声明任何中间件
     *
     * 只告警、不阻断（部署可能是内网直连或由上游网关鉴权），但必须让运维看见——
     * 「不写 middleware」在这套框架里等价于「匿名可达」。
     */
    protected function warnWhenAnonymous(array $config): void
    {
        $group      = $config['groups']['workflow'] ?? [];
        $middleware = array_merge(
            (array) ($config['middleware'] ?? []),
            (array) (is_array($group) ? ($group['middleware'] ?? []) : [])
        );

        if ($middleware !== []) {
            return;
        }

        $message = '[wise-workflow] /api/workflow/* 路由组已启用但未声明任何中间件：'
            . '状态变更接口当前匿名可达。请在 config/wise-workflow.php 的 '
            . 'web.groups.workflow.middleware 中声明认证中间件，'
            . '或设置 web.enable=false 关闭该组（安全审计阶段 1 / A）。';

        try {
            ContextualLogger::warning($message, ['workflow', 'security']);
        } catch (\Throwable $e) {
            error_log($message);
        }
    }
}
