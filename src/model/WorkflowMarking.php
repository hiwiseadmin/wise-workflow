<?php
declare(strict_types=1);

namespace wise\workflow\model;

use think\Model;

/**
 * 工作流标记模型（Workflow Marking Model）
 *
 * 对应数据表：workflow_marking
 * 用于存储工作流实例的当前状态（标记）
 *
 * 字段说明：
 * - id：主键
 * - subject：工作流实例标识
 * - subject_type：主体类型（如 'order', 'document'）
 * - marking：当前状态节点集合（JSON 格式）
 * - definition_version：工作流定义版本号
 * - assigned_to：当前处理人用户ID
 * - entered_at：进入当前状态的时间
 * - last_sla_at：上次 SLA 超时处理时间（用于幂等保护，避免同一超时周期内重复触发）
 * - version：乐观锁版本号
 * - create_time：创建时间
 * - update_time：更新时间
 *
 * @package wise\workflow\model
 */
class WorkflowMarking extends Model
{
    protected $name = 'workflow_marking';

    /**
     * 注意：不要在此声明「字段名列表」。
     *
     * think-orm 的 `Model::$schema` 语义是 **字段类型映射**（`['name' => 'string', ...]`），
     * 框架内部按 `array_keys($schema)` 推导写入白名单与字段类型
     * （见 vendor/topthink/think-orm/src/Model.php::checkAllowFields()）。
     * 若写成扁平数组，推导出的白名单会变成 [0, 1, 2 …]，任何真实字段写入都会被
     * `Builder::update()` 以 `fields not exists:[marking]` 拒绝。
     *
     * 字段类型交由 `$type` 与数据库内省获取，语义更准确、无需与迁移手工同步。
     */
    protected $type = [
        'marking'            => 'json',
        'definition_version' => 'integer',
        'assigned_to'        => 'integer',
        'version'            => 'integer',
    ];

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $dateFormat = 'datetime';
}
