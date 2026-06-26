<?php
declare(strict_types=1);

namespace wise\workflow\model;

use think\Model;

/**
 * 工作流并行审批任务模型（Workflow Task Model）
 * 
 * 对应数据表：workflow_task
 * 用于支持会签/或签/比例签等高级审批模式
 * 
 * 审批策略：
 * - all:   会签——全部审批通过才流转
 * - any:   或签——任一人审批即可
 * - ratio: 比例签——通过比例达到 policy_value 时流转（如 0.6 表示 60%）
 * - count: 数量签——通过人数达到 policy_value 时流转（如 2 表示 2 人）
 * 
 * 任务状态：
 * - pending:    待处理
 * - completed:  已通过
 * - rejected:   已驳回
 * - delegated:  已转交
 * - cancelled:  已取消
 * 
 * @package wise\workflow\model
 */
class WorkflowTask extends Model
{
    protected $name = 'workflow_task';

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-workflow.table_names.task', $this->name));
    }

    protected $schema = [
        'id',
        'subject',
        'transition_name',
        'task_key',
        'assignee',
        'status',
        'policy',
        'policy_value',
        'result',
        'comment',
        'completed_at',
        'create_time',
        'update_time',
    ];

    protected $type = [
        'assignee' => 'integer',
    ];

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $dateFormat   = 'datetime';

    // ─── 状态常量 ─────────────────────────────

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_DELEGATED = 'delegated';
    const STATUS_CANCELLED = 'cancelled';

    // ─── 策略常量 ─────────────────────────────

    const POLICY_ALL   = 'all';
    const POLICY_ANY   = 'any';
    const POLICY_RATIO = 'ratio';
    const POLICY_COUNT = 'count';
}
