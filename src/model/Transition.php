<?php
declare(strict_types=1);

namespace wise\workflow\model;

/**
 * 工作流转换（Transition）类
 *
 * 表示工作流中从一个或多个状态节点到另一个或多个状态节点的转换
 * 每个转换可以有守卫条件（guard），只有满足条件时才能执行转换
 *
 * 元数据支持：
 * - label: 显示名称（如 '提交审核'）
 * - description: 操作描述
 * - group: 分组名称
 * - sort: 排序权重
 * - icon: 按钮图标
 * - color: 按钮颜色
 * - confirm: 是否需要二次确认（bool）
 * - confirm_message: 确认提示文本
 * - is_reversible: 是否可撤回
 *
 * 守卫条件支持两种模式：
 * 1. callable guard（代码级）：传统回调函数
 * 2. guard_expression（声明式）：简单表达式字符串，运行时解析
 *
 * @package wise\workflow
 */
class Transition
{
    /**
     * 转换名称
     * @var string
     */
    protected $name;
    
    /**
     * 源状态节点（一个或多个）
     * @var array
     */
    protected $from;
    
    /**
     * 目标状态节点（一个或多个）
     * @var array
     */
    protected $to;
    
    /**
     * 守卫条件（回调函数）
     *
     * 当提供了守卫条件时，只有该函数返回 true 才允许转换
     * 函数签名：function(array $marking, array $context): bool
     *
     * @var callable|null
     */
    protected $guard;

    /**
     * 声明式条件表达式（与 guard 互斥）
     *
     * 简单表达式语法，例如：
     * - "context.amount > 1000"
     * - "context.user_role == 'admin'"
     *
     * @var string|null
     */
    protected $guardExpression;

    /**
     * 元数据（label/sort/icon/color/confirm 等）
     * @var array
     */
    protected $metadata = [];

    /**
     * 构造函数
     *
     * @param string        $name            转换名称
     * @param string|array  $from            源状态节点
     * @param string|array  $to              目标状态节点
     * @param callable|null $guard           守卫条件回调
     * @param array         $metadata        元数据
     * @param string|null   $guardExpression 声明式条件表达式
     */
    public function __construct(
        string $name,
        $from,
        $to,
        ?callable $guard = null,
        array $metadata = [],
        ?string $guardExpression = null
    ) {
        $this->name            = $name;
        $this->from            = is_array($from) ? $from : [$from];
        $this->to              = is_array($to) ? $to : [$to];
        $this->guard           = $guard;
        $this->metadata        = $metadata;
        $this->guardExpression = $guardExpression;
    }

    /**
     * 获取转换名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取源状态节点列表
     */
    public function getFrom(): array
    {
        return $this->from;
    }

    /**
     * 获取目标状态节点列表
     */
    public function getTo(): array
    {
        return $this->to;
    }

    /**
     * 获取守卫条件回调函数
     */
    public function getGuard(): ?callable
    {
        return $this->guard;
    }

    /**
     * 设置守卫条件回调函数
     */
    public function setGuard(?callable $guard): void
    {
        $this->guard = $guard;
    }

    /**
     * 获取声明式条件表达式
     */
    public function getGuardExpression(): ?string
    {
        return $this->guardExpression;
    }

    /**
     * 设置声明式条件表达式
     */
    public function setGuardExpression(?string $expression): void
    {
        $this->guardExpression = $expression;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 设置元数据
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * 获取指定元数据键值
     */
    public function getMeta(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 是否可撤回
     */
    public function isReversible(): bool
    {
        return !empty($this->metadata['is_reversible']);
    }

    /**
     * 是否需要二次确认
     */
    public function requireConfirm(): bool
    {
        return !empty($this->metadata['confirm']);
    }

    /**
     * 获取确认提示文本
     */
    public function getConfirmMessage(): string
    {
        return $this->metadata['confirm_message'] ?? '确认执行此操作？';
    }

    /**
     * 获取显示标签
     */
    public function getLabel(): string
    {
        return $this->metadata['label'] ?? $this->name;
    }

    /**
     * 获取排序权重
     */
    public function getSort(): int
    {
        return (int)($this->metadata['sort'] ?? 0);
    }

    /**
     * 是否为并行审批转换
     */
    public function isParallel(): bool
    {
        return !empty($this->metadata['parallel']);
    }

    /**
     * 获取并行审批配置
     *
     * @return array|null 配置数组或 null
     *   {
     *     "mode": "all|any|ratio|count",
     *     "value": "0.6" | "2" | null,
     *     "assignees": [101, 102, 103]
     *   }
     */
    public function getParallelConfig(): ?array
    {
        return $this->metadata['parallel'] ?? null;
    }

    /**
     * 获取并行审批模式
     */
    public function getParallelMode(): string
    {
        $config = $this->getParallelConfig();
        return $config['mode'] ?? 'all';
    }

    /**
     * 获取并行审批策略值
     */
    public function getParallelPolicyValue()
    {
        $config = $this->getParallelConfig();
        return $config['value'] ?? null;
    }

    /**
     * 序列化为数组
     */
    public function toArray(): array
    {
        return [
            'name'             => $this->name,
            'from'             => $this->from,
            'to'               => $this->to,
            'metadata'         => $this->metadata,
            'guard_expression' => $this->guardExpression,
            'has_guard'        => $this->guard !== null,
            'is_parallel'      => $this->isParallel(),
            'parallel'         => $this->getParallelConfig(),
        ];
    }

    /**
     * 从数组创建 Transition
     */
    public static function fromArray(array $data): self
    {
        $name            = $data['name'] ?? '';
        $from            = $data['from'] ?? [];
        $to              = $data['to'] ?? [];
        $metadata        = $data['metadata'] ?? [];
        $guardExpression = $data['guard_expression'] ?? null;
        return new static($name, $from, $to, null, $metadata, $guardExpression);
    }
}
