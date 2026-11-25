<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\WorkflowRegistry;
use wise\workflow\exception\WorkflowException;

/**
 * 守卫条件检查管道（Evaluate Guards Pipe）
 *
 * 检查 Transition 上注册的守卫条件是否满足。
 * 支持两种守卫类型：
 *   1. callable guard（闭包回调）
 *   2. guard_expression（声明式表达式）
 *
 * 两种守卫可以通过 WorkflowRegistry::buildGuard() 组合使用。
 *
 * 守卫失败时触发 guard_failed 事件，并抛出 WorkflowException。
 */
class EvaluateGuardsPipe implements TransitionPipeInterface
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
        $transition = $payload->transition;

        $guard = WorkflowRegistry::buildGuard($transition);

        if (!$guard) {
            $payload->log(__CLASS__, 'skipped', '无守卫条件');
            return $next($payload);
        }

        $guardContext = array_merge($payload->context, [
            'transition_name' => $payload->transitionName,
            'workflow_name'   => $payload->getWorkflowName(),
        ]);

        if ($guard($payload->marking, $guardContext)) {
            $payload->log(__CLASS__, 'passed', '守卫条件满足');
            return $next($payload);
        }

        $this->dispatchGuardFailedEvent($payload);

        throw new WorkflowException(
            "守卫条件不满足 [{$payload->transitionName}]"
        );
    }

    /**
     * 触发 guard_failed 事件
     */
    protected function dispatchGuardFailedEvent(TransitionPayload $payload): void
    {
        $eventService = $this->pipeline->getEventService();
        if (!$eventService) {
            return;
        }

        $workflow   = $payload->workflow;
        $transition = $payload->transition;
        $eventName  = "workflow.{$workflow->getName()}.guard_failed";

        $eventService->trigger($eventName, [
            'workflow'    => $workflow,
            'transition'  => $transition,
            'subject'     => $payload->subject,
            'context'     => $payload->context,
            'event_name'  => $eventName,
        ]);

        $payload->log(__CLASS__, 'failed', '守卫失败');
    }
}
