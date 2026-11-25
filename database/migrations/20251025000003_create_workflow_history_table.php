<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWorkflowHistoryTable extends Migrator
{
    protected function getTable(): string
    {
        return 'workflow_history';
    }

    /**
     * Up Method.
     *
     * 创建工作流历史记录表，记录每次状态转换的详细信息
     * 包含操作人、IP、User-Agent 等审计信息
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '工作流历史记录表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('subject', 100)->setComment('流程实例标识'))
              ->addColumn(Column::string('transition_name', 50)->setComment('转换名称'))
              ->addColumn(Column::string('from_place', 50)->setNullable()->setComment('源状态'))
              ->addColumn(Column::string('to_place', 50)->setNullable()->setComment('目标状态'))
              ->addColumn(Column::text('context')->setNullable()->setComment('上下文（JSON格式）'))
              ->addColumn(Column::string('remark', 500)->setNullable()->setDefault('')->setComment('操作备注'))
              ->addColumn(Column::string('type', 20)->setDefault('normal')->setComment('操作类型：normal/delegate/rollback/init'))
              ->addColumn(Column::integer('user_id')->setNullable()->setComment('操作用户ID'))
              ->addColumn(Column::string('ip', 45)->setNullable()->setComment('操作IP'))
              ->addColumn(Column::string('user_agent', 255)->setNullable()->setComment('User-Agent'))
              ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
              ->addIndex('subject', ['name' => 'idx_subject'])
              ->addIndex('transition_name', ['name' => 'idx_transition'])
              ->addIndex('create_time', ['name' => 'idx_created'])
              ->create();
    }

    /**
     * Down Method.
     *
     * 删除工作流历史记录表
     */
    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
