<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\exception\WorkflowException;

/**
 * 权限检查管道（Check Permission Pipe）
 *
 * 调用注入的权限服务检查当前用户是否有权限执行此转换。
 * 权限服务必须实现 check(Transition $transition, array $context) 方法。
 *
 * 如果没有注入权限服务，则跳过检查。
 */
class CheckPermissionPipe implements TransitionPipeInterface
{
    /**
     * 管道引用（由 TransitionPipeline::resolvePipeInstance 自动注入）
     * @var TransitionPipeline
     */
    protected $pipeline;

    public function __construct(TransitionPipeline $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        if (!empty($payload->context['auto_sla'] ?? false)) {
            $payload->log(__CLASS__, 'skipped', '系统自动触发(auto_sla)，跳过权限校验');
            return $next($payload);
        }

        $permissionService = $this->pipeline->getPermissionService();

        if ($permissionService === null) {
            if ((bool) config('wise-workflow.permission.allow_all', false)) {
                $payload->log(__CLASS__, 'skipped', '未注入权限服务且 permission.allow_all=true，放行');
                return $next($payload);
            }

            throw new WorkflowException(
                '权限校验失败：未注入权限服务，拒绝转换'
                . '（确实不需要权限系统时请配置 wise-workflow.permission.allow_all = true）'
            );
        }

        if (!method_exists($permissionService, 'check')) {
            throw new WorkflowException('权限校验失败：权限服务未实现 check() 方法，拒绝转换');
        }

        $result = $permissionService->check($payload->transition, $payload->context);

        if ($result === false) {
            throw new WorkflowException(
                "权限校验失败：无权执行转换 [{$payload->transitionName}]"
            );
        }

        $payload->log(__CLASS__, 'passed');
        return $next($payload);
    }
}
