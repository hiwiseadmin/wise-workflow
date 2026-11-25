<?php
declare(strict_types=1);

namespace wise\workflow;

use wise\workflow\model\Place;
use wise\workflow\model\Transition;

/**
 * 工作流定义类（Workflow Definition）
 *
 * 表示一个完整的工作流定义，包含：
 * - 状态节点（Places）：支持元数据（label/color/icon 等）
 * - 转换规则（Transitions）：支持元数据和声明式守卫表达式
 * - 初始状态（Initial Place）
 * - 元数据（Metadata）
 * - 版本号
 *
 * @package wise\workflow
 */
class Workflow
{
    /**
     * 工作流名称
     * @var string
     */
    protected $name;
    
    /**
     * 状态节点集合
     * @var array<string, Place>
     */
    protected $places = [];
    
    /**
     * 转换规则集合
     * @var array<string, Transition>
     */
    protected $transitions = [];
    
    /**
     * 初始状态节点名称
     * @var string|null
     */
    protected $initialPlace;
    
    /**
     * 元数据（title/description/version 等）
     * @var array
     */
    protected $metadata = [];

    /**
     * 构造函数
     */
    public function __construct(string $name, array $places = [], array $transitions = [], $initialPlace = null)
    {
        $this->name = $name;
        $this->places = $places;
        $this->transitions = $transitions;
        $this->initialPlace = $initialPlace;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPlaces(): array
    {
        return $this->places;
    }

    public function getTransitions(): array
    {
        return $this->transitions;
    }

    public function getInitialPlace()
    {
        return $this->initialPlace;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

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
     * 获取版本号
     */
    public function getVersion(): int
    {
        return (int)($this->metadata['version'] ?? 1);
    }

    /**
     * 获取版本策略
     */
    public function getVersionPolicy(): string
    {
        return $this->metadata['version_policy'] ?? 'strict';
    }

    /**
     * 添加状态节点
     */
    public function addPlace(Place $place): void
    {
        $this->places[$place->getName()] = $place;
    }

    /**
     * 添加转换规则
     */
    public function addTransition(Transition $transition): void
    {
        $this->transitions[$transition->getName()] = $transition;
    }

    /**
     * 设置状态节点集合（替换）
     */
    public function setPlaces(array $places): void
    {
        $this->places = [];
        foreach ($places as $place) {
            if ($place instanceof Place) {
                $this->places[$place->getName()] = $place;
            }
        }
    }

    /**
     * 设置转换规则集合（替换）
     */
    public function setTransitions(array $transitions): void
    {
        $this->transitions = [];
        foreach ($transitions as $transition) {
            if ($transition instanceof Transition) {
                $this->transitions[$transition->getName()] = $transition;
            }
        }
    }

    /**
     * 设置初始状态
     */
    public function setInitialPlace(?string $initialPlace): void
    {
        $this->initialPlace = $initialPlace;
    }

    /**
     * 序列化为数组（前端可用）
     */
    public function toArray(): array
    {
        $placesData = [];
        foreach ($this->places as $place) {
            $placesData[] = $place->toArray();
        }

        $transitionsData = [];
        foreach ($this->transitions as $transition) {
            $transitionsData[] = $transition->toArray();
        }

        return [
            'name'          => $this->name,
            'places'        => $placesData,
            'transitions'   => $transitionsData,
            'initial_place' => $this->initialPlace,
            'metadata'      => $this->metadata,
        ];
    }
}
