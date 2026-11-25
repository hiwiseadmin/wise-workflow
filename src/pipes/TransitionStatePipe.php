<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;

/**
 * 状态转换管道（Transition State Pipe）
 *
 * 执行核心的状态置换逻辑：从 marking 中移除源状态（from places），
 * 添加目标状态（to places），并保存旧状态快照到 oldMarking。
 *
 * 这是审批流程的核心步骤——此 Pipe 本身不持久化，由后续
 * OptimisticLockSavePipe 负责保存到存储。
 */
class TransitionStatePipe implements TransitionPipeInterface
{
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        $transition = $payload->transition;
        $fromPlaces = $transition->getFrom();
        $toPlaces   = $transition->getTo();

        $payload->oldMarking = $payload->marking;

        foreach ($fromPlaces as $from) {
            unset($payload->marking[$from]);
        }

        foreach ($toPlaces as $to) {
            $payload->marking[$to] = 1;
        }

        $payload->log(__CLASS__, 'passed',
            "{$payload->getOldMarkingString()} → {$payload->getMarkingString()}"
        );

        return $next($payload);
    }
}
