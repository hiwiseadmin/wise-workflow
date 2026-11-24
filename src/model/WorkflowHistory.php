<?php
declare(strict_types=1);

namespace wise\workflow\model;

use think\Model;

/**
 * 工作流历史记录模型（Workflow History Model）
 *
 * 对应数据表：workflow_history
 * 用于记录工作流转换的详细历史
 *
 * 字段说明：
 * - id：主键
 * - subject：工作流实例标识
 * - transition_name：转换名称
 * - from_place：源状态
 * - to_place：目标状态
 * - context：上下文信息（JSON 格式）
 * - user_id：操作用户ID
 * - remark：操作备注
 * - type：操作类型（normal/delegate/rollback/init）
 * - ip：操作IP地址
 * - user_agent：用户浏览器标识
 * - create_time：创建时间
 *
 * @package wise\workflow\model
 */
class WorkflowHistory extends Model
{
    protected $name = 'workflow_history';

    /**
     * 注意：不要在此声明「字段名列表」。
     *
     * `Model::$schema` 在 think-orm 中是**字段类型映射**（`['name' => 'string']`），
     * 框架按 `array_keys()` 推导写入白名单；写成扁平数组会让白名单变成 [0, 1, 2 …]，
     * 导致任何字段写入都被 `Builder::update()` 以 `fields not exists:[...]` 拒绝。
     * 字段白名单与类型统一以数据库内省 + `$type` 为准。
     */


    protected $type = [
        'context' => 'json',
    ];

    protected $autoWriteTimestamp = false;

    const TYPE_NORMAL   = 'normal';
    const TYPE_DELEGATE = 'delegate';
    const TYPE_ROLLBACK = 'rollback';
    const TYPE_INIT     = 'init';
}
