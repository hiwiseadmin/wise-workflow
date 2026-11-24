<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;

/**
 * 加载当前状态（Load Marking Pipe）
 *
 * 从 MarkingStore 读取当前主体的状态标记和乐观锁版本号，
 * 填充到 TransitionPayload::$marking 和 ::$version 中。
 */
class LoadMarkingPipe implements TransitionPipeInterface
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
        $markingStore = $this->pipeline->getMarkingStore();

        $payload->marking = $markingStore->getMarking($payload->subject);
        $payload->version = $markingStore->getVersion($payload->subject);

        $payload->log(__CLASS__, 'passed', 
            '状态: ' . $payload->getMarkingString() . ', 版本: ' . $payload->version
        );

        return $next($payload);
    }
}
