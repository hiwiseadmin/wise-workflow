<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\exception\WorkflowException;

/**
 * 乐观锁保存管道（Optimistic Lock Save Pipe）
 *
 * 将状态转换结果通过 MarkingStore::setMarking() 持久化。
 * 支持乐观锁并发控制：如果版本冲突则抛出 WorkflowException，
 * 由外层重试循环捕获并重试整个管道。
 *
 * 乐观锁抛出的异常消息包含 "乐观锁冲突" 关键词，Engine 的 apply()
 * 通过检测该关键词来判断是否应该重试。
 */
class OptimisticLockSavePipe implements TransitionPipeInterface
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
        $lockConfig   = $this->pipeline->getLockConfig();

        $lockEnabled     = $lockConfig['enabled'] ?? true;
        $expectedVersion = $lockEnabled ? $payload->version : 0;

        $newVersion = $markingStore->setMarking(
            $payload->subject,
            $payload->marking,
            $payload->context,
            $expectedVersion
        );

        $payload->saved   = true;
        $payload->version = $newVersion;

        $payload->log(__CLASS__, 'passed',
            "已保存, 版本: {$expectedVersion} → {$newVersion}"
        );

        return $next($payload);
    }
}
