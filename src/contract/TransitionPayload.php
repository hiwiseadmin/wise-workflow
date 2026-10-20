<?php
declare(strict_types=1);

namespace wise\workflow\contract;

use wise\workflow\model\Transition;
use wise\workflow\Workflow;
use wise\support\traits\Conditionable;

/**
 * 管道载荷（Transition Payload）
 * 
 * 在审批管道中流转的上下文对象，设计为可变对象（mutable）。
 * 每个 Pipe 可以读取和修改载荷中的状态，实现步骤间的数据传递。
 * 
 * 支持 Conditionable Trait，允许链式条件构建。
 * 
 * 生命周期：
 *   创建 → Pipe1 修改 → Pipe2 修改 → ... → 返回最终 marking
 * 
 * @package wise\workflow\contract
 */
class TransitionPayload
{
    use Conditionable;
    /**
     * 工作流定义
     * @var Workflow
     */
    public $workflow;

    /**
     * 主体标识（如 "order:123"）
     * @var string
     */
    public $subject;

    /**
     * 转换名称
     * @var string
     */
    public $transitionName;

    /**
     * 转换定义（由 FindTransitionPipe 设置）
     * @var Transition|null
     */
    public $transition;

    /**
     * 当前状态标记（由 LoadMarkingPipe 设置）
     * @var array
     */
    public $marking = [];

    /**
     * 旧状态标记（状态转换前的快照，由 TransitionStatePipe 设置）
     * @var array
     */
    public $oldMarking = [];

    /**
     * 当前乐观锁版本号（由 LoadMarkingPipe 设置）
     * @var int
     */
    public $version = 0;

    /**
     * 上下文（调用方传入的额外数据，Pipe 可追加）
     * @var array
     */
    public $context = [];

    /**
     * 是否已保存到存储（由 OptimisticLockSavePipe 设置）
     * @var bool
     */
    public $saved = false;

    /**
     * 管道执行日志（用于调试追踪）
     * @var array
     */
    public $pipeLog = [];

    /**
     * 构造函数
     *
     * @param Workflow $workflow       工作流定义
     * @param string   $subject        主体标识
     * @param string   $transitionName 转换名称
     * @param array    $context        上下文
     */
    public function __construct(
        Workflow $workflow,
        string $subject,
        string $transitionName,
        array $context = []
    ) {
        $this->workflow       = $workflow;
        $this->subject        = $subject;
        $this->transitionName = $transitionName;
        $this->context        = $context;
    }

    /**
     * 记录管道执行日志
     *
     * @param string $pipeClass Pipe 类名
     * @param string $status    状态：passed / skipped / failed
     * @param string $message   附加消息
     */
    public function log(string $pipeClass, string $status, string $message = ''): void
    {
        $this->pipeLog[] = [
            'pipe'     => $pipeClass,
            'status'   => $status,
            'message'  => $message,
            'time'     => microtime(true),
        ];
    }

    /**
     * 获取工作流名称
     */
    public function getWorkflowName(): string
    {
        return $this->workflow->getName();
    }

    /**
     * 获取当前状态描述（逗号分隔）
     */
    public function getMarkingString(): string
    {
        return implode(',', array_keys($this->marking));
    }

    /**
     * 获取旧状态描述（逗号分隔）
     */
    public function getOldMarkingString(): string
    {
        return implode(',', array_keys($this->oldMarking));
    }

    // ─── Fluent 链式方法（配合 Conditionable 使用）───

    /**
     * 链式设置上下文数据
     *
     * @return $this
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * 链式追加单条日志
     *
     * @return $this
     */
    public function withLog(string $pipeClass, string $status, string $message = ''): self
    {
        $this->log($pipeClass, $status, $message);
        return $this;
    }

    /**
     * 链式设置状态标记
     *
     * @return $this
     */
    public function withMarking(array $marking): self
    {
        $this->marking = $marking;
        return $this;
    }

    /**
     * 链式设置版本号
     *
     * @return $this
     */
    public function withVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }
}
