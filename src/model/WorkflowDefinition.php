<?php
declare(strict_types=1);

namespace wise\workflow\model;

use think\Model;

/**
 * 工作流定义模型（Workflow Definition Model）
 *
 * 对应数据表：workflow_definition
 * 用于存储工作流的静态定义（状态节点、转换规则等）
 *
 * 字段说明：
 * - id：主键
 * - name：工作流名称（唯一标识）
 * - title：工作流显示名称
 * - description：工作流描述
 * - places：状态节点（JSON 格式，支持元数据）
 * - transitions：转换规则（JSON 格式，支持 guard_expression 和元数据）
 * - initial_place：初始状态
 * - sla_config：SLA 超时配置（JSON 格式）
 * - status：状态（1=启用，0=禁用）
 * - version：版本号，每次修改定义自动递增
 * - version_policy：版本策略（strict/migrate/legacy）
 * - create_time：创建时间
 * - update_time：更新时间
 *
 * @package wise\workflow\model
 */
class WorkflowDefinition extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     */
    protected $name = 'workflow_definition';

    /**
     * 注意：不要在此声明「字段名列表」。
     *
     * `Model::$schema` 在 think-orm 中是**字段类型映射**（`['name' => 'string']`），
     * 框架按 `array_keys()` 推导写入白名单；写成扁平数组会让白名单变成 [0, 1, 2 …]，
     * 导致任何字段写入都被 `Builder::update()` 以 `fields not exists:[...]` 拒绝。
     * 字段白名单与类型统一以数据库内省 + `$type` 为准。
     */

    protected $type = [
        'places'         => 'json',
        'transitions'    => 'json',
        'sla_config'     => 'json',
        'status'         => 'integer',
        'version'        => 'integer',
    ];

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $dateFormat = 'datetime';

    /**
     * 自动递增版本号（保存前）
     */
    public static function onBeforeUpdate($model): void
    {
        if ($model->isDirty() && !$model->isDirty('version')) {
            $model->version = ((int)$model->version) + 1;
        }
    }
}
