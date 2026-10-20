<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\model\WorkflowHistory;

/**
 * 历史记录管道（Record History Pipe）
 * 
 * 在状态转换保存成功后，将本次转换记录到工作流历史表中。
 * 记录内容包含：主体标识、转换名称、源状态、目标状态、上下文、操作类型等。
 */
class RecordHistoryPipe implements TransitionPipeInterface
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
        $markingStore   = $this->pipeline->getMarkingStore();
        $fromStr        = $payload->getOldMarkingString();
        $toStr          = $payload->getMarkingString();

        $markingStore->recordHistory(
            $payload->subject,
            $payload->transitionName,
            $fromStr,
            $toStr,
            $payload->context,
            WorkflowHistory::TYPE_NORMAL
        );

        $payload->log(__CLASS__, 'passed',
            "历史记录: {$fromStr} → {$toStr}"
        );

        return $next($payload);
    }
}
