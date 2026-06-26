<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\Place;
use wise\workflow\Transition;
use wise\workflow\Workflow;

/**
 * Workflow 工作流定义类单元测试
 *
 * 覆盖：
 * - 构造函数（空参、带参）
 * - getName()、getPlaces()、getTransitions()、getInitialPlace()
 * - getMetadata()、setMetadata()
 * - addPlace()、addTransition()
 * - 多次添加/覆盖行为
 */
class WorkflowTest extends TestCase
{
    // ==================== 构造函数测试 ====================

    public function testConstructWithNameOnly(): void
    {
        $workflow = new Workflow('order');

        $this->assertSame('order', $workflow->getName());
        $this->assertSame([], $workflow->getPlaces());
        $this->assertSame([], $workflow->getTransitions());
        $this->assertNull($workflow->getInitialPlace());
        $this->assertSame([], $workflow->getMetadata());
    }

    public function testConstructWithAllParameters(): void
    {
        $places = [
            'draft' => new Place('draft'),
            'review' => new Place('review'),
        ];
        $transitions = [
            'submit' => new Transition('submit', 'draft', 'review'),
        ];

        $workflow = new Workflow('order', $places, $transitions, 'draft');

        $this->assertSame('order', $workflow->getName());
        $this->assertSame($places, $workflow->getPlaces());
        $this->assertSame($transitions, $workflow->getTransitions());
        $this->assertSame('draft', $workflow->getInitialPlace());
    }

    public function testConstructWithPartialParameters(): void
    {
        $places = ['draft' => new Place('draft')];
        $workflow = new Workflow('order', $places);

        $this->assertSame($places, $workflow->getPlaces());
        $this->assertSame([], $workflow->getTransitions());
        $this->assertNull($workflow->getInitialPlace());
    }

    // ==================== addPlace 测试 ====================

    public function testAddSinglePlace(): void
    {
        $workflow = new Workflow('order');
        $place = new Place('draft');

        $workflow->addPlace($place);

        $places = $workflow->getPlaces();
        $this->assertCount(1, $places);
        $this->assertArrayHasKey('draft', $places);
        $this->assertSame($place, $places['draft']);
        $this->assertSame('draft', $places['draft']->getName());
    }

    public function testAddMultiplePlaces(): void
    {
        $workflow = new Workflow('order');
        $placeNames = ['draft', 'review', 'approved', 'rejected'];

        foreach ($placeNames as $name) {
            $workflow->addPlace(new Place($name));
        }

        $places = $workflow->getPlaces();
        $this->assertCount(4, $places);

        foreach ($placeNames as $name) {
            $this->assertArrayHasKey($name, $places);
            $this->assertSame($name, $places[$name]->getName());
        }
    }

    public function testAddPlaceOverwritesExisting(): void
    {
        $workflow = new Workflow('order');
        $place1 = new Place('draft');
        $place2 = new Place('draft');

        $workflow->addPlace($place1);
        $workflow->addPlace($place2);

        $places = $workflow->getPlaces();
        $this->assertCount(1, $places);
        $this->assertSame($place2, $places['draft']);
    }

    // ==================== addTransition 测试 ====================

    public function testAddSingleTransition(): void
    {
        $workflow = new Workflow('order');
        $transition = new Transition('submit', 'draft', 'review');

        $workflow->addTransition($transition);

        $transitions = $workflow->getTransitions();
        $this->assertCount(1, $transitions);
        $this->assertArrayHasKey('submit', $transitions);
        $this->assertSame($transition, $transitions['submit']);
        $this->assertSame('submit', $transitions['submit']->getName());
    }

    public function testAddMultipleTransitions(): void
    {
        $workflow = new Workflow('order');
        $workflow->addTransition(new Transition('submit', 'draft', 'review'));
        $workflow->addTransition(new Transition('approve', 'review', 'approved'));
        $workflow->addTransition(new Transition('reject', 'review', 'rejected'));

        $transitions = $workflow->getTransitions();
        $this->assertCount(3, $transitions);

        $this->assertArrayHasKey('submit', $transitions);
        $this->assertArrayHasKey('approve', $transitions);
        $this->assertArrayHasKey('reject', $transitions);
    }

    public function testAddTransitionOverwritesExisting(): void
    {
        $workflow = new Workflow('order');
        $transition1 = new Transition('submit', 'draft', 'review');
        $transition2 = new Transition('submit', 'draft', 'approved');

        $workflow->addTransition($transition1);
        $workflow->addTransition($transition2);

        $transitions = $workflow->getTransitions();
        $this->assertCount(1, $transitions);
        $this->assertSame($transition2, $transitions['submit']);
        $this->assertSame(['approved'], $transitions['submit']->getTo());
    }

    // ==================== 元数据测试 ====================

    public function testGetMetadataInitiallyEmpty(): void
    {
        $workflow = new Workflow('order');
        $this->assertSame([], $workflow->getMetadata());
    }

    public function testSetAndGetMetadata(): void
    {
        $workflow = new Workflow('order');
        $metadata = [
            'title' => '订单审批流程',
            'description' => '用于订单的审批管理',
        ];

        $workflow->setMetadata($metadata);

        $this->assertSame($metadata, $workflow->getMetadata());
        $this->assertSame('订单审批流程', $workflow->getMetadata()['title']);
        $this->assertSame('用于订单的审批管理', $workflow->getMetadata()['description']);
    }

    public function testSetMetadataOverwritesAll(): void
    {
        $workflow = new Workflow('order');
        $workflow->setMetadata(['title' => '旧标题', 'version' => 1]);
        $workflow->setMetadata(['title' => '新标题']);

        $metadata = $workflow->getMetadata();
        $this->assertSame('新标题', $metadata['title']);
        $this->assertArrayNotHasKey('version', $metadata);
    }

    public function testSetMetadataEmptyArray(): void
    {
        $workflow = new Workflow('order');
        $workflow->setMetadata(['title' => 'Test']);
        $this->assertNotEmpty($workflow->getMetadata());

        $workflow->setMetadata([]);
        $this->assertSame([], $workflow->getMetadata());
    }

    // ==================== 完整工作流定义测试 ====================

    public function testCompleteWorkflowDefinition(): void
    {
        $workflow = new Workflow('order_approval');

        // 添加状态节点
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('pending_review'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addPlace(new Place('rejected'));

        // 添加转换规则
        $workflow->addTransition(new Transition('submit', 'draft', 'pending_review'));
        $workflow->addTransition(new Transition('approve', 'pending_review', 'approved'));
        $workflow->addTransition(new Transition('reject', 'pending_review', 'rejected'));

        // 添加带守卫的转换
        $workflow->addTransition(new Transition(
            'auto_approve',
            'draft',
            'approved',
            function (array $marking, array $context): bool {
                return ($context['amount'] ?? 0) < 100;
            }
        ));

        // 设置元数据
        $workflow->setMetadata([
            'title' => '订单审批流程',
            'description' => '标准订单审批工作流',
            'version' => '1.0.0',
        ]);

        // 验证
        $this->assertSame('order_approval', $workflow->getName());
        $this->assertCount(4, $workflow->getPlaces());
        $this->assertCount(4, $workflow->getTransitions());
        $this->assertCount(3, $workflow->getMetadata());

        $this->assertArrayHasKey('draft', $workflow->getPlaces());
        $this->assertArrayHasKey('pending_review', $workflow->getPlaces());
        $this->assertArrayHasKey('approved', $workflow->getPlaces());
        $this->assertArrayHasKey('rejected', $workflow->getPlaces());

        $this->assertArrayHasKey('submit', $workflow->getTransitions());
        $this->assertArrayHasKey('approve', $workflow->getTransitions());
        $this->assertArrayHasKey('reject', $workflow->getTransitions());
        $this->assertArrayHasKey('auto_approve', $workflow->getTransitions());
    }

    // ==================== 不同名称测试 ====================

    public function testDifferentWorkflowNames(): void
    {
        $names = ['order', 'document', 'leave_request', 'purchase', 'onboarding'];

        foreach ($names as $name) {
            $workflow = new Workflow($name);
            $this->assertSame($name, $workflow->getName());
        }
    }

    public function testChineseWorkflowName(): void
    {
        $workflow = new Workflow('请假审批');
        $this->assertSame('请假审批', $workflow->getName());
    }
}
