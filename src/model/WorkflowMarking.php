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
 * - version：乐观锁版本号
 * - create_time：创建时间
 * - update_time：更新时间
 * 
 * @package wise\workflow\model
 */
class WorkflowMarking extends Model
{
    protected $name = 'workflow_marking';

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-workflow.table_names.marking', $this->name));
    }

    protected $schema = [
        'id',
        'subject',
        'subject_type',
        'marking',
        'definition_version',
        'assigned_to',
        'entered_at',
        'version',
        'create_time',
        'update_time',
    ];

    protected $type = [
        'definition_version' => 'integer',
        'assigned_to'        => 'integer',
        'version'            => 'integer',
    ];

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $dateFormat = 'datetime';
}
