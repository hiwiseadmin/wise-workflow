<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

class CreateWorkflowDefinitionTable extends Migrator
{
    protected function getTable(): string
    {
        return 'workflow_definition';
    }

    /**
     * Up Method.
     *
     * 创建工作流定义表，存储工作流的静态定义
     * 包含状态节点、转换规则等核心配置
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine'    => 'InnoDB',
            'comment'   => '工作流定义表',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if ($table->exists()) {
            return;
        }

        $table->addColumn(Column::string('name', 50)->setComment('工作流名称（唯一标识）'))
              ->addColumn(Column::string('title', 100)->setComment('工作流显示名称'))
              ->addColumn(Column::string('description', 255)->setNullable()->setComment('工作流描述'))
              ->addColumn(Column::text('places')->setComment('状态节点（JSON格式）'))
              ->addColumn(Column::text('transitions')->setComment('转换规则（JSON格式）'))
              ->addColumn(Column::string('initial_place', 50)->setNullable()->setComment('初始状态'))
              ->addColumn(Column::boolean('status')->setDefault(1)->setComment('状态：1=启用，0=禁用'))
              // ↓ 已合并 UpgradeWorkflowTables (definition部分)
              ->addColumn(Column::integer('version')->setDefault(1)->setComment('版本号'))
              ->addColumn(Column::string('version_policy', 20)->setDefault('strict')->setComment('版本策略：strict/migrate/legacy'))
              ->addColumn(Column::text('sla_config')->setNullable()->setComment('SLA 超时配置（JSON）'))
              ->addColumn(Column::dateTime('create_time')->setNullable()->setComment('创建时间'))
              ->addColumn(Column::dateTime('update_time')->setNullable()->setComment('更新时间'))
              ->addIndex('name', ['unique' => true, 'name' => 'uk_name'])
              ->create();
    }

    /**
     * Down Method.
     *
     * 删除工作流定义表
     */
    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
