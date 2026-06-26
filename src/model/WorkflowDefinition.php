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

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-workflow.table_names.workflow', $this->name));
    }

    protected $schema = [
        'id',
        'name',
        'title',
        'description',
        'places',
        'transitions',
        'initial_place',
        'sla_config',
        'status',
        'version',
        'version_policy',
        'create_time',
        'update_time',
    ];

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
        if ($model->isDirty()) {
            $model->version = ((int)$model->version) + 1;
        }
    }
}
