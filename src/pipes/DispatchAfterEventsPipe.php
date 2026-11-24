<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;

/**
 * 后置事件管道（Dispatch After Events Pipe）
 *
 * 在状态转换保存 + 历史记录完成后，触发以下事件：
 *   1. after 事件（转换成功）
 *     格式：workflow.{name}.after.{transitionName}
 *   2. enter 事件（进入新状态）
 *     格式：workflow.{name}.enter.{placeName}
 *   3. leave 事件（离开旧状态）
 *     格式：workflow.{name}.leave.{placeName}
 *   4. completed 事件（到达终态）
 *     格式：workflow.{name}.completed
 *
 * 如果没有注入事件服务，则跳过所有事件。
 */
class DispatchAfterEventsPipe implements TransitionPipeInterface
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

        $workflow = $payload->workflow;
        $wfName   = $workflow->getName();

        $this->dispatchAfterEvent($eventService, $wfName, $payload);

        $this->dispatchPlaceEvents($eventService, $wfName, $payload);

        if ($this->isCompleted($workflow, $payload->marking)) {
            $this->dispatchCompletedEvent($eventService, $wfName, $payload);
        }

        $payload->log(__CLASS__, 'passed', '所有后置事件已触发');
        return $next($payload);
    }

    /**
     * after 事件
     * @param object $eventService 事件服务（需实现 trigger(string $event, array $payload) 方法）
     */
    protected function dispatchAfterEvent(object $eventService, string $wfName, TransitionPayload $payload): void
    {
        $transition  = $payload->transition;
        $eventService->trigger("workflow.{$wfName}.after.{$transition->getName()}", [
            'workflow'   => $payload->workflow,
            'transition' => $transition,
            'subject'    => $payload->subject,
            'context'    => $payload->context,
        ]);
    }

    /**
     * enter / leave 事件
     * @param object $eventService 事件服务（需实现 trigger 方法）
     */
    protected function dispatchPlaceEvents(object $eventService, string $wfName, TransitionPayload $payload): void
    {
        $oldMarking = $payload->oldMarking;
        $newMarking = $payload->marking;

        foreach ($oldMarking as $placeName => $val) {
            if (!isset($newMarking[$placeName])) {
                $eventService->trigger("workflow.{$wfName}.leave.{$placeName}", [
                    'workflow'   => $payload->workflow,
                    'place'      => $placeName,
                    'subject'    => $payload->subject,
                    'context'    => $payload->context,
                    'event_name' => "workflow.{$wfName}.leave.{$placeName}",
                ]);
            }
        }

        foreach ($newMarking as $placeName => $val) {
            if (!isset($oldMarking[$placeName])) {
                $eventService->trigger("workflow.{$wfName}.enter.{$placeName}", [
                    'workflow'   => $payload->workflow,
                    'place'      => $placeName,
                    'subject'    => $payload->subject,
                    'context'    => $payload->context,
                    'event_name' => "workflow.{$wfName}.enter.{$placeName}",
                ]);
            }
        }
    }

    /**
     * completed 事件
     * @param object $eventService 事件服务（需实现 trigger 方法）
     */
    protected function dispatchCompletedEvent(object $eventService, string $wfName, TransitionPayload $payload): void
    {
        $eventService->trigger("workflow.{$wfName}.completed", [
            'workflow'   => $payload->workflow,
            'subject'    => $payload->subject,
            'context'    => $payload->context,
            'event_name' => "workflow.{$wfName}.completed",
        ]);
    }

    /**
     * 检查是否到达终态
     *
     * 两种判断：
     *   1. 当前状态节点标记了 is_ended
     *   2. 没有任何可用的出边转换（且标记不为空）
     */
    protected function isCompleted(\wise\workflow\Workflow $workflow, array $marking): bool
    {
        $places = $workflow->getPlaces();

        foreach ($marking as $placeName => $val) {
            if (isset($places[$placeName]) && $places[$placeName]->isEnded()) {
                return true;
            }
        }

        $hasOutgoing = false;
        foreach ($workflow->getTransitions() as $t) {
            foreach ($t->getFrom() as $from) {
                if (isset($marking[$from])) {
                    $hasOutgoing = true;
                    break 2;
                }
            }
        }

        return !$hasOutgoing && !empty($marking);
    }
}
