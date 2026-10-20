<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\exception\WorkflowException;

/**
 * 前置状态校验管道（Validate From Places Pipe）
 * 
 * 验证当前对象是否处于转换定义中要求的前置状态（from places）。
 * 如果当前标记中不包含所需的任一个 from 状态，则抛出异常。
 */
class ValidateFromPlacesPipe implements TransitionPipeInterface
{
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        if ($payload->transition === null) {
            throw new WorkflowException(
                'Transition 未设置，请确保 FindTransitionPipe 在 ValidateFromPlacesPipe 之前执行'
            );
        }

        $fromPlaces = $payload->transition->getFrom();
        $marking    = $payload->marking;

        foreach ($fromPlaces as $from) {
            if (!isset($marking[$from])) {
                $payload->log(__CLASS__, 'failed', "缺失前置状态: {$from}");
                // 安全（审计阶段 1 / E-1）：实例**实际状态**只进日志与 data，不再进响应消息
                throw new WorkflowException(
                    "当前状态不匹配：需要前置状态 [{$from}]",
                    ['expected' => $from, 'actual' => array_keys($marking)]
                );
            }
        }

        $payload->log(__CLASS__, 'passed', '前置状态验证通过');
        return $next($payload);
    }
}
