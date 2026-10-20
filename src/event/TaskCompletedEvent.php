<?php
declare(strict_types=1);

namespace wise\workflow\event;

/**
 * 任务完成事件（Task Completed Event）
 * 
 * V2: 当并行审批任务满足触发条件时由 TaskManager 发出，
 * 替代原来 TaskManager → Engine::apply() 的直接调用。
 * 
 * 监听器签名为：
 *   function(TaskCompletedEvent $event): void
 * 
 * 事件载荷包含完整的执行上下文，监听器可以调用 Engine::apply()。
 * 
 * @package wise\workflow
 */
class TaskCompletedEvent
{
    /**
     * 主体标识
     * @var string
     */
    public $subject;

    /**
     * 转换名称
     * @var string
     */
    public $transitionName;

    /**
     * 审批策略
     * @var string
     */
    public $policy;

    /**
     * 上下文（含 comment/remark 等）
     * @var array
     */
    public $context;

    /**
     * 构造函数
     *
     * @param string $subject        主体标识（如 "order:123"）
     * @param string $transitionName 转换名称
     * @param string $policy         审批策略
     * @param array  $context        上下文
     */
    public function __construct(
        string $subject,
        string $transitionName,
        string $policy,
        array $context = []
    ) {
        $this->subject        = $subject;
        $this->transitionName = $transitionName;
        $this->policy         = $policy;
        $this->context        = $context;
    }
}
