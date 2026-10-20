<?php
declare(strict_types=1);

namespace wise\workflow\pipes;

use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\TransitionPipeline;
use wise\workflow\exception\WorkflowException;

/**
 * 版本校验管道（Verify Version Pipe）
 * 
 * 检查工作流实例的版本与当前定义版本是否一致。
 * 支持三种策略：
 *   - strict:  版本不一致直接拒绝（默认）
 *   - migrate: 自动迁移（Phase 3 实现）
 *   - legacy:  旧实例沿用旧定义运行（Phase 3 实现）
 */
class VerifyVersionPipe implements TransitionPipeInterface
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
        $marking = $payload->marking;

        // 未初始化的实例跳过版本校验
        if (empty($marking)) {
            $payload->log(__CLASS__, 'skipped', '未初始化');
            return $next($payload);
        }

        $workflow   = $payload->workflow;
        $defVersion = $workflow->getVersion();

        if ($defVersion <= 0) {
            $payload->log(__CLASS__, 'skipped', '无版本号');
            return $next($payload);
        }

        $policy = $workflow->getVersionPolicy();

        if ($policy === 'strict') {
            // 从 marking 存储中读取实际记录的 definition_version
            // （而非 context 中注入的定义版本，确保校验的是数据库真实值）
            $markingStore = $this->pipeline->getMarkingStore();
            $instanceVersion = $markingStore->getDefinitionVersion($payload->subject);

            if ($instanceVersion <= 0) {
                $payload->log(__CLASS__, 'skipped', '实例未记录定义版本，跳过版本校验');
            } elseif ($instanceVersion !== $defVersion) {
                // 安全（审计阶段 1 / E-1）：版本号只进 data/日志，不进响应消息
                throw new WorkflowException(
                    '工作流定义版本不一致，请先迁移该实例',
                    ['instance_version' => $instanceVersion, 'definition_version' => $defVersion]
                );
            }
        }
        // migrate 和 legacy 策略由业务层自行处理

        $payload->log(__CLASS__, 'passed', "版本策略: {$policy}");
        return $next($payload);
    }
}
