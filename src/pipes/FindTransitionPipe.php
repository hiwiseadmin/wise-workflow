<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\exception\WorkflowException;

/**
 * 查找转换定义（Find Transition Pipe）
 *
 * 在工作流定义中查找指定名称的 Transition 对象，
 * 填充到 TransitionPayload::$transition 中。
 *
 * 这是管道的第一道工序，是后续所有步骤的前提。
 */
class FindTransitionPipe implements TransitionPipeInterface
{
    public function handle(TransitionPayload $payload, \Closure $next): TransitionPayload
    {
        $workflow      = $payload->workflow;
        $transitionName = $payload->transitionName;

        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->getName() === $transitionName) {
                $payload->transition = $transition;
                $payload->log(__CLASS__, 'passed', "找到转换: {$transitionName}");
                return $next($payload);
            }
        }

        throw new WorkflowException(
            "转换 [{$transitionName}] 在工作流 [{$workflow->getName()}] 中不存在"
        );
    }
}
