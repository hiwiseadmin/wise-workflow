<?php
declare(strict_types=1);

namespace wise\workflow\model;

/**
 * 工作流状态节点（Place）类
 * 
 * 表示工作流中的一个状态节点，例如：草稿、审核中、已发布等
 * 每个状态节点有一个唯一名称，用于在工作流定义中引用
 * 
 * 元数据支持：
 * - label: 显示名称（如 '待审核'）
 * - description: 状态描述
 * - color: 状态颜色（如 '#FFD700'）
 * - icon: 状态图标（如 'clock'）
 * - sort: 排序权重
 * - ended: 是否为终态
 * 
 * @package wise\workflow
 */
class Place
{
    /**
     * 状态节点名称
     * @var string
     */
    protected $name;

    /**
     * 元数据（label/description/color/icon 等）
     * @var array
     */
    protected $metadata = [];

    /**
     * 构造函数
     * 
     * @param string $name     状态节点名称，例如：'draft'、'review'、'approved'
     * @param array  $metadata 元数据（可选）
     */
    public function __construct(string $name, array $metadata = [])
    {
        $this->name = $name;
        $this->metadata = $metadata;
    }

    /**
     * 获取状态节点名称
     * 
     * @return string 状态节点名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取元数据
     * 
     * @return array 元数据数组
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 设置元数据
     * 
     * @param array $metadata 元数据
     * @return void
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * 获取指定元数据键值
     * 
     * @param string $key     键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function getMeta(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 判断是否为终态
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return !empty($this->metadata['ended']);
    }

    /**
     * 转换为字符串
     * 
     * 当对象被当作字符串使用时（例如 echo $place），返回状态节点名称
     * 
     * @return string 状态节点名称
     */
    public function __toString()
    {
        return $this->name;
    }

    /**
     * 序列化为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * 从数组创建 Place
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $name     = $data['name'] ?? ($data[0] ?? '');
        $metadata = $data['metadata'] ?? (is_array($data) && !isset($data['name']) ? [] : []);
        return new static((string)$name, (array)$metadata);
    }
}
