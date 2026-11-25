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

    /**
     * 注意：不要在此声明「字段名列表」。
     *
     * `Model::$schema` 在 think-orm 中是**字段类型映射**（`['name' => 'string']`），
     * 框架按 `array_keys()` 推导写入白名单；写成扁平数组会让白名单变成 [0, 1, 2 …]，
     * 导致任何字段写入都被 `Builder::update()` 以 `fields not exists:[...]` 拒绝。
     * 字段白名单与类型统一以数据库内省 + `$type` 为准。
     */


    protected $type = [
        'assignee'     => 'integer',
        'policy_value' => 'float',
        'result'       => 'string',
    ];

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $dateFormat   = 'datetime';


    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_DELEGATED = 'delegated';
    const STATUS_CANCELLED = 'cancelled';


    const POLICY_ALL   = 'all';
    const POLICY_ANY   = 'any';
    const POLICY_RATIO = 'ratio';
    const POLICY_COUNT = 'count';
}
