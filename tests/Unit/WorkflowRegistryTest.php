<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\Workflow;
use wise\workflow\WorkflowRegistry;
use wise\workflow\exception\WorkflowException;

/**
 * WorkflowRegistry 工作流注册表单元测试
 *
 * 注意：WorkflowRegistry 使用静态属性存储工作流，测试间需要清理状态
 *
 * 覆盖：
 * - add()、get()、has()、all()、clear() 静态方法
 * - 注册、查找、覆盖、异常情况
 */
class WorkflowRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        WorkflowRegistry::clear();
    }

    protected function tearDown(): void
    {
        WorkflowRegistry::clear();
    }

    // ==================== add / get 测试 ====================

    public function testAddAndGet(): void
    {
        $workflow = new Workflow('order');
        WorkflowRegistry::add($workflow);

        $retrieved = WorkflowRegistry::get('order');
        $this->assertSame($workflow, $retrieved);
    }

    public function testAddMultipleWorkflows(): void
    {
        $orderWorkflow = new Workflow('order');
        $docWorkflow = new Workflow('document');

        WorkflowRegistry::add($orderWorkflow);
        WorkflowRegistry::add($docWorkflow);

        $this->assertSame($orderWorkflow, WorkflowRegistry::get('order'));
        $this->assertSame($docWorkflow, WorkflowRegistry::get('document'));
    }

    public function testGetThrowsExceptionWhenNotFound(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Workflow [non_existent] not found");

        WorkflowRegistry::get('non_existent');
    }

    public function testAddOverwritesExistingWorkflow(): void
    {
        $workflow1 = new Workflow('order');
        $workflow2 = new Workflow('order');

        WorkflowRegistry::add($workflow1);
        WorkflowRegistry::add($workflow2);

        // 应该返回最新的工作流
        $this->assertSame($workflow2, WorkflowRegistry::get('order'));
    }

    // ==================== has 测试 ====================

    public function testHasReturnsTrueWhenExists(): void
    {
        WorkflowRegistry::add(new Workflow('order'));
        $this->assertTrue(WorkflowRegistry::has('order'));
    }

    public function testHasReturnsFalseWhenNotExists(): void
    {
        $this->assertFalse(WorkflowRegistry::has('non_existent'));
    }

    public function testHasAfterClear(): void
    {
        WorkflowRegistry::add(new Workflow('order'));
        $this->assertTrue(WorkflowRegistry::has('order'));

        WorkflowRegistry::clear();
        $this->assertFalse(WorkflowRegistry::has('order'));
    }

    // ==================== all 测试 ====================

    public function testAllReturnsEmptyInitially(): void
    {
        $this->assertSame([], WorkflowRegistry::all());
    }

    public function testAllReturnsAllWorkflows(): void
    {
        $orderWorkflow = new Workflow('order');
        $docWorkflow = new Workflow('document');

        WorkflowRegistry::add($orderWorkflow);
        WorkflowRegistry::add($docWorkflow);

        $all = WorkflowRegistry::all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('order', $all);
        $this->assertArrayHasKey('document', $all);
        $this->assertSame($orderWorkflow, $all['order']);
        $this->assertSame($docWorkflow, $all['document']);
    }

    // ==================== clear 测试 ====================

    public function testClearRemovesAllWorkflows(): void
    {
        WorkflowRegistry::add(new Workflow('order'));
        WorkflowRegistry::add(new Workflow('document'));

        $this->assertCount(2, WorkflowRegistry::all());

        WorkflowRegistry::clear();
        $this->assertCount(0, WorkflowRegistry::all());
        $this->assertSame([], WorkflowRegistry::all());
    }

    public function testClearThenAdd(): void
    {
        WorkflowRegistry::add(new Workflow('old_workflow'));
        WorkflowRegistry::clear();

        $newWorkflow = new Workflow('new_workflow');
        WorkflowRegistry::add($newWorkflow);

        $all = WorkflowRegistry::all();
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('new_workflow', $all);
        $this->assertArrayNotHasKey('old_workflow', $all);
    }

    // ==================== 高级场景测试 ====================

    public function testCompleteWorkflowCycle(): void
    {
        // 创建完整的工作流
        $workflow = new Workflow('order');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('review'));
        $workflow->addTransition(new Transition('submit', 'draft', 'review'));

        // 注册
        WorkflowRegistry::add($workflow);

        // 验证
        $this->assertTrue(WorkflowRegistry::has('order'));
        $retrieved = WorkflowRegistry::get('order');
        $this->assertSame('order', $retrieved->getName());
        $this->assertCount(2, $retrieved->getPlaces());
        $this->assertCount(1, $retrieved->getTransitions());
    }

    public function testGetExceptionMessage(): void
    {
        try {
            WorkflowRegistry::get('unknown_workflow');
            $this->fail('Expected WorkflowException was not thrown');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('unknown_workflow', $e->getMessage());
        }
    }

    public function testMultipleClearAndReAdd(): void
    {
        for ($i = 0; $i < 3; $i++) {
            WorkflowRegistry::add(new Workflow("wf_{$i}"));
        }
        $this->assertCount(3, WorkflowRegistry::all());

        WorkflowRegistry::clear();
        $this->assertCount(0, WorkflowRegistry::all());

        $replacement = new Workflow('replacement');
        WorkflowRegistry::add($replacement);

        $all = WorkflowRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame($replacement, WorkflowRegistry::get('replacement'));
    }
}
