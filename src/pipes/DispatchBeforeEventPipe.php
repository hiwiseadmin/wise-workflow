<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;

/**
 * Before 事件管道（Dispatch Before Event Pipe）
 *
 * 在执行状态转换前触发 before 事件。
 * 监听器可通过抛出异常来中止转换（如业务校验不通过）。
 *
 * 事件名格式：workflow.{workflowName}.before.{transitionName}
 * 事件载荷：workflow / transition / subject / context
 */
class DispatchBeforeEventPipe implements TransitionPipeInterface
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
        $eventService = $this->pipeline->getEventService();
        if (!$eventService) {
            $payload->log(__CLASS__, 'skipped', '未注入事件服务');
            return $next($payload);
        }

        $workflow   = $payload->workflow;
        $transition = $payload->transition;
        $eventName  = "workflow.{$workflow->getName()}.before.{$transition->getName()}";

        $eventService->trigger($eventName, [
            'workflow'   => $workflow,
            'transition' => $transition,
            'subject'    => $payload->subject,
            'context'    => $payload->context,
        ]);

        $payload->log(__CLASS__, 'passed', $eventName);
        return $next($payload);
    }
}
