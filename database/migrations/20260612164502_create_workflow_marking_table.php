<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWorkflowMarkingTable extends Migrator
{
    protected function getTable(): string
    {
        return config('wise-workflow.table_names.marking', 'workflow_marking');
    }

    /**
     * Up Method.
     *
     * 创建状态标记表，存储流程实例的当前状态
     * 每个流程实例（subject）唯一对应一条记录
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '工作流标记表（存储流程实例当前状态）',
            'collation' => 'utf8mb4_general_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('subject', 100)->setComment('流程实例标识'))
              // ↓ 已合并 UpgradeWorkflowTables (marking部分)
              ->addColumn(Column::string('subject_type', 50)->setNullable()->setComment('主体类型'))
              ->addColumn(Column::text('marking')->setComment('当前标记（JSON格式）'))
              ->addColumn(Column::integer('version')->setDefault(0)->setComment('乐观锁版本号'))
              ->addColumn(Column::integer('definition_version')->setDefault(0)->setComment('工作流定义版本号'))
              ->addColumn(Column::integer('assigned_to')->setNullable()->setDefault(0)->setComment('当前处理人ID'))
              ->addColumn(Column::datetime('entered_at')->setNullable()->setComment('进入当前状态时间'))
              ->addColumn(Column::datetime('create_time')->setNullable()->setComment('创建时间'))
              ->addColumn(Column::datetime('update_time')->setNullable()->setComment('更新时间'))
              ->addIndex('subject', ['unique' => true, 'name' => 'uk_subject'])
              ->addIndex('subject_type', ['name' => 'idx_subject_type'])
              ->create();
    }

    /**
     * Down Method.
     *
     * 删除状态标记表
     */
    public function down(): void
    {
        $this->table($this->getTable())->drop();
    }
}
