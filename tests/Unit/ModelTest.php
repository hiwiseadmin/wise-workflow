<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\model\WorkflowDefinition;
use wise\workflow\model\WorkflowHistory;
use wise\workflow\model\WorkflowMarking;
use wise\workflow\model\WorkflowTask;

/**
 * 模型类单元测试
 *
 * 验证常量、表名定义（think\Model 由真实 ThinkPHP 提供，$schema 在运行时会被
 * 框架 initialize() 重置，此处不校验 schema 键，仅校验类常量与表名）。
 */
class ModelTest extends TestCase
{
    public function testWorkflowTaskConstants(): void
    {
        $this->assertSame('pending', WorkflowTask::STATUS_PENDING);
        $this->assertSame('completed', WorkflowTask::STATUS_COMPLETED);
        $this->assertSame('rejected', WorkflowTask::STATUS_REJECTED);
        $this->assertSame('delegated', WorkflowTask::STATUS_DELEGATED);
        $this->assertSame('all', WorkflowTask::POLICY_ALL);
        $this->assertSame('any', WorkflowTask::POLICY_ANY);
        $this->assertSame('ratio', WorkflowTask::POLICY_RATIO);
        $this->assertSame('count', WorkflowTask::POLICY_COUNT);
    }

    public function testWorkflowTaskTableName(): void
    {
        $task = new WorkflowTask();
        $this->assertSame('workflow_task', $this->readProp($task, 'name'));
        $this->assertIsArray($this->readProp($task, 'schema'));
    }

    public function testWorkflowMarkingTableName(): void
    {
        $marking = new WorkflowMarking();
        $this->assertSame('workflow_marking', $this->readProp($marking, 'name'));
        $this->assertIsArray($this->readProp($marking, 'schema'));
    }

    public function testWorkflowHistoryTableNameAndConstants(): void
    {
        $history = new WorkflowHistory();
        $this->assertSame('workflow_history', $this->readProp($history, 'name'));
        $this->assertIsArray($this->readProp($history, 'schema'));
        $this->assertSame('normal', WorkflowHistory::TYPE_NORMAL);
        $this->assertSame('delegate', WorkflowHistory::TYPE_DELEGATE);
        $this->assertSame('rollback', WorkflowHistory::TYPE_ROLLBACK);
        $this->assertSame('init', WorkflowHistory::TYPE_INIT);
    }

    public function testWorkflowDefinitionTableNameAndSchema(): void
    {
        $def = new WorkflowDefinition();
        $this->assertSame('workflow_definition', $this->readProp($def, 'name'));
        $this->assertIsArray($this->readProp($def, 'schema'));
    }

    private function readProp(object $obj, string $prop)
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }
}
