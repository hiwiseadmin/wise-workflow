<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * WiseWorkflow Phase 3a — 并行审批任务表
 * 
 * 为会签/或签/比例签等高级审批模式提供基础设施
 * 不侵入已有的三张表（definition / marking / history）
 */
class CreateWorkflowTaskTable extends Migrator
{
    protected function getTable(): string
    {
        return config('wise-workflow.table_names.task', 'workflow_task');
    }

    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '工作流并行审批任务表',
            'collation' => 'utf8mb4_general_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('subject', 100)->setComment('工作流实例标识'))
              ->addColumn(Column::string('transition_name', 50)->setComment('关联的转换名称'))
              ->addColumn(Column::string('task_key', 50)->setComment('任务唯一标识（同一 subject+transition 内唯一）'))
              ->addColumn(Column::integer('assignee')->setDefault(0)->setComment('处理人用户ID'))
              ->addColumn(Column::string('status', 20)->setDefault('pending')->setComment('状态：pending/completed/rejected/delegated/cancelled'))
              ->addColumn(Column::string('policy', 20)->setDefault('all')->setComment('审批策略：all/any/ratio/count'))
              ->addColumn(Column::string('policy_value', 50)->setNullable()->setComment('策略参数值（如 0.6 或 2）'))
              ->addColumn(Column::string('result', 100)->setNullable()->setComment('处理结果'))
              ->addColumn(Column::string('comment', 500)->setNullable()->setComment('审批意见'))
              ->addColumn(Column::dateTime('completed_at')->setNullable()->setComment('完成时间'))
              ->addColumn(Column::dateTime('create_time')->setNullable()->setComment('创建时间'))
              ->addColumn(Column::dateTime('update_time')->setNullable()->setComment('更新时间'))
              ->addIndex('subject', ['name' => 'idx_subject'])
              ->addIndex('transition_name', ['name' => 'idx_transition'])
              ->addIndex(['subject', 'transition_name'], ['name' => 'idx_subject_transition'])
              ->addIndex('assignee', ['name' => 'idx_assignee'])
              ->addIndex('status', ['name' => 'idx_status'])
              ->create();
    }

    public function down(): void
    {
        $this->table($this->getTable())->drop();
    }
}
