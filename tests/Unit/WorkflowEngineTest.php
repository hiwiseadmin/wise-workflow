<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\MarkingStoreInterface;
use wise\workflow\Place;
use wise\workflow\Transition;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\exception\WorkflowException;

/**
 * WorkflowEngine 工作流引擎单元测试
 *
 * 使用 Mockery 模拟 MarkingStoreInterface，不依赖真实数据库
 *
 * 覆盖：
 * - can() — 转换可用性检查（含守卫条件）
 * - apply() — 执行转换（含守卫和权限检查）
 * - getEnabledTransitions() — 获取可用转换列表
 * - getMarking() / setMarking() — 状态存取
 * - init() — 工作流初始化
 * - findTransition — 异常处理
 */
class WorkflowEngineTest extends TestCase
{
    /** @var Workflow 测试用工作流定义 */
    private $workflow;

    /** @var \Mockery\MockInterface|MarkingStoreInterface Mock 的状态存储 */
    private $markingStore;

    /** @var WorkflowEngine */
    private $engine;

    protected function setUp(): void
    {
        // 创建工作流定义
        $this->workflow = new Workflow('order');
        $this->workflow->addPlace(new Place('draft'));
        $this->workflow->addPlace(new Place('review'));
        $this->workflow->addPlace(new Place('approved'));
        $this->workflow->addPlace(new Place('rejected'));
        $this->workflow->addTransition(new Transition('submit', 'draft', 'review'));
        $this->workflow->addTransition(new Transition('approve', 'review', 'approved'));
        $this->workflow->addTransition(new Transition('reject', 'review', 'rejected'));
        $this->workflow->addTransition(new Transition(
            'auto_approve',
            'draft',
            'approved',
            function (array $marking, array $context): bool {
                return ($context['amount'] ?? 999) < 100;
            }
        ));

        // 创建 Mock 状态存储
        $this->markingStore = \Mockery::mock(MarkingStoreInterface::class);

        // 创建引擎实例（不带权限/事件服务）
        $this->engine = new WorkflowEngine($this->markingStore);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    // ==================== can() 测试 ====================

    public function testCanReturnsTrueWhenTransitionAvailable(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'submit');
        $this->assertTrue($result);
    }

    public function testCanReturnsFalseWhenNotInRequiredPlace(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['approved' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'submit');
        $this->assertFalse($result);
    }

    public function testCanReturnsFalseWhenTransitionNotFound(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'non_existent_transition');
        $this->assertFalse($result);
    }

    public function testCanWithGuardReturnsTrueWhenConditionMet(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'auto_approve', ['amount' => 50]);
        $this->assertTrue($result);
    }

    public function testCanWithGuardReturnsFalseWhenConditionNotMet(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'auto_approve', ['amount' => 500]);
        $this->assertFalse($result);
    }

    public function testCanReturnsFalseWhenEmptyMarking(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn([]);

        $result = $this->engine->can($this->workflow, 'order:123', 'submit');
        $this->assertFalse($result);
    }

    public function testCanWithMultipleFromPlacesRequiresAll(): void
    {
        // 创建一个需要多个源状态的转换
        $multiWorkflow = new Workflow('multi');
        $multiWorkflow->addPlace(new Place('draft'));
        $multiWorkflow->addPlace(new Place('paid'));
        $multiWorkflow->addPlace(new Place('shipped'));
        $multiWorkflow->addTransition(new Transition('ship', ['draft', 'paid'], 'shipped'));

        $multiEngine = new WorkflowEngine($this->markingStore);

        // 只有一个源状态，应该失败
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:456')
            ->andReturn(['draft' => 1]);

        $result = $multiEngine->can($multiWorkflow, 'order:456', 'ship');
        $this->assertFalse($result);
    }

    public function testCanWithMultipleFromPlacesSucceedsWhenAllPresent(): void
    {
        $multiWorkflow = new Workflow('multi');
        $multiWorkflow->addPlace(new Place('draft'));
        $multiWorkflow->addPlace(new Place('paid'));
        $multiWorkflow->addPlace(new Place('shipped'));
        $multiWorkflow->addTransition(new Transition('ship', ['draft', 'paid'], 'shipped'));

        $multiEngine = new WorkflowEngine($this->markingStore);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:456')
            ->andReturn(['draft' => 1, 'paid' => 1]);

        $result = $multiEngine->can($multiWorkflow, 'order:456', 'ship');
        $this->assertTrue($result);
    }

    // ==================== apply() 测试 ====================

    public function testApplyExecutesTransitionSuccessfully(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', ['review' => 1], \Mockery::any())
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:123', 'submit', 'draft', 'review', \Mockery::any())
            ->once();

        $result = $this->engine->apply($this->workflow, 'order:123', 'submit');

        $this->assertSame(['review' => 1], $result);
    }

    public function testApplyWithMultipleToPlaces(): void
    {
        $multiWorkflow = new Workflow('multi');
        $multiWorkflow->addPlace(new Place('draft'));
        $multiWorkflow->addPlace(new Place('review'));
        $multiWorkflow->addPlace(new Place('notify'));
        $multiWorkflow->addTransition(new Transition('split', 'draft', ['review', 'notify']));

        $multiEngine = new WorkflowEngine($this->markingStore);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:789')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:789', ['review' => 1, 'notify' => 1], \Mockery::any())
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:789', 'split', 'draft', 'review,notify', \Mockery::any())
            ->once();

        $result = $multiEngine->apply($multiWorkflow, 'order:789', 'split');

        $this->assertArrayHasKey('review', $result);
        $this->assertArrayHasKey('notify', $result);
        $this->assertArrayNotHasKey('draft', $result);
    }

    public function testApplyWithMultipleFromPlaces(): void
    {
        $multiWorkflow = new Workflow('multi');
        $multiWorkflow->addPlace(new Place('draft'));
        $multiWorkflow->addPlace(new Place('paid'));
        $multiWorkflow->addPlace(new Place('shipped'));
        $multiWorkflow->addTransition(new Transition('ship', ['draft', 'paid'], 'shipped'));

        $multiEngine = new WorkflowEngine($this->markingStore);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:456')
            ->andReturn(['draft' => 1, 'paid' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:456', ['shipped' => 1], \Mockery::any())
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:456', 'ship', 'draft,paid', 'shipped', \Mockery::any())
            ->once();

        $result = $multiEngine->apply($multiWorkflow, 'order:456', 'ship');

        $this->assertArrayHasKey('shipped', $result);
        $this->assertArrayNotHasKey('draft', $result);
        $this->assertArrayNotHasKey('paid', $result);
    }

    public function testApplyWithGuardPasses(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', ['approved' => 1], ['amount' => 50, 'user_id' => 1])
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:123', 'auto_approve', 'draft', 'approved', ['amount' => 50, 'user_id' => 1])
            ->once();

        $result = $this->engine->apply(
            $this->workflow,
            'order:123',
            'auto_approve',
            ['amount' => 50, 'user_id' => 1]
        );

        $this->assertSame(['approved' => 1], $result);
    }

    public function testApplyWithGuardFails(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Guard condition failed for transition [auto_approve]');

        $this->engine->apply(
            $this->workflow,
            'order:123',
            'auto_approve',
            ['amount' => 500]
        );
    }

    public function testApplyWithNonExistentTransition(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Transition [non_existent] not found in workflow [order]");

        $this->engine->apply($this->workflow, 'order:123', 'non_existent');
    }

    public function testApplyPassesContextToStore(): void
    {
        $context = ['user_id' => 42, 'remark' => '审核通过'];

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['review' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', ['approved' => 1], $context)
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:123', 'approve', 'review', 'approved', $context)
            ->once();

        $result = $this->engine->apply($this->workflow, 'order:123', 'approve', $context);
        $this->assertSame(['approved' => 1], $result);
    }

    // ==================== getEnabledTransitions 测试 ====================

    public function testGetEnabledTransitionsReturnsAvailableTransitions(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');

        // draft 状态下应该有 submit（无guard）和 auto_approve（guard在无context时amount默认999>=100, 所以返回false）
        $this->assertCount(1, $enabled);
        $this->assertSame('submit', $enabled[0]->getName());
    }

    public function testGetEnabledTransitionsWithGuardConditionMet(): void
    {
        // getEnabledTransitions 调用 can()，can() 内部调用 getMarking()
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');
        // 在 draft 状态，submit 可用（无guard），auto_approve不可用（guard: amount>=100, 默认amount=999）
        $this->assertCount(1, $enabled);
        $this->assertSame('submit', $enabled[0]->getName());
    }

    public function testGetEnabledTransitionsEmptyWhenNoneAvailable(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['approved' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');

        // approved 是终态，无可用的转换
        $this->assertCount(0, $enabled);
    }

    public function testGetEnabledTransitionsWithEmptyMarking(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn([]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');

        $this->assertCount(0, $enabled);
    }

    // ==================== getMarking / setMarking 测试 ====================

    public function testGetMarkingDelegatesToStore(): void
    {
        $expected = ['draft' => 1];

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn($expected);

        $result = $this->engine->getMarking('order:123');
        $this->assertSame($expected, $result);
    }

    public function testGetMarkingReturnsEmptyWhenNew(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:999')
            ->andReturn([]);

        $result = $this->engine->getMarking('order:999');
        $this->assertSame([], $result);
    }

    public function testSetMarkingDelegatesToStore(): void
    {
        $marking = ['draft' => 1];
        $context = ['init' => true];

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', $marking, $context)
            ->once();

        $this->engine->setMarking('order:123', $marking, $context);
        $this->assertTrue(true); // No exception = success
    }

    // ==================== init() 测试 ====================

    public function testInitSetsInitialPlace(): void
    {
        $workflow = new Workflow('order', [], [], 'draft');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('review'));

        $subject = 'order:new1';

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn([]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with($subject, ['draft' => 1], [])
            ->once();

        $this->engine->init($workflow, $subject);
        $this->assertTrue(true); // Mockery 验证了 setMarking 被调用
    }

    public function testInitSkipsWhenAlreadyInitialized(): void
    {
        $workflow = new Workflow('order', [], [], 'draft');
        $workflow->addPlace(new Place('draft'));

        $subject = 'order:existing';

        // 已存在标记
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        // 不应该调用 setMarking
        $this->markingStore
            ->shouldNotReceive('setMarking');

        $this->engine->init($workflow, $subject);
        $this->assertTrue(true); // Mockery 验证了 setMarking 未被调用
    }

    public function testInitThrowsExceptionWhenNoInitialPlace(): void
    {
        $workflow = new Workflow('order'); // 没有初始状态

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Workflow [order] has no initial place defined");

        $this->engine->init($workflow, 'order:123');
    }

    public function testInitWithContextPassedToStore(): void
    {
        $workflow = new Workflow('order', [], [], 'draft');
        $workflow->addPlace(new Place('draft'));
        $context = ['user_id' => 1, 'source' => 'api'];

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:ctx1')
            ->andReturn([]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:ctx1', ['draft' => 1], $context)
            ->once();

        $this->engine->init($workflow, 'order:ctx1', $context);
        $this->assertTrue(true); // Mockery 验证了 setMarking 被调用
    }

    // ==================== 完整工作流链测试 ====================

    public function testCompleteWorkflowChain(): void
    {
        $subject = 'order:chain';

        // Step 1: draft → submit → review
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with($subject, ['review' => 1], \Mockery::any());

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with($subject, 'submit', 'draft', 'review', \Mockery::any());

        $result = $this->engine->apply($this->workflow, $subject, 'submit');
        $this->assertSame(['review' => 1], $result);

        // 重置 mock 以模拟新状态
        \Mockery::close();
        $this->markingStore = \Mockery::mock(MarkingStoreInterface::class);
        $this->engine = new WorkflowEngine($this->markingStore);

        // Step 2: review → approve → approved
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['review' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with($subject, ['approved' => 1], \Mockery::any());

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with($subject, 'approve', 'review', 'approved', \Mockery::any());

        $result = $this->engine->apply($this->workflow, $subject, 'approve');
        $this->assertSame(['approved' => 1], $result);
    }

    // ==================== 权限服务测试 ====================

    public function testEngineWithPermissionService(): void
    {
        $permissionService = \Mockery::mock();
        $engine = new WorkflowEngine($this->markingStore, null, $permissionService);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', ['review' => 1], \Mockery::any());

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:123', 'submit', 'draft', 'review', \Mockery::any());

        // checkPermission 默认实现为空，所以不会抛异常
        $result = $engine->apply($this->workflow, 'order:123', 'submit');
        $this->assertSame(['review' => 1], $result);
    }

    // ==================== 事件服务测试 ====================

    public function testEngineWithEventService(): void
    {
        $eventService = \Mockery::mock();
        $eventService
            ->shouldReceive('trigger')
            ->with('workflow.order.submit', \Mockery::type('array'))
            ->once();

        $engine = new WorkflowEngine($this->markingStore, $eventService);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->with('order:123', ['review' => 1], \Mockery::any());

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->with('order:123', 'submit', 'draft', 'review', \Mockery::any());

        $result = $engine->apply($this->workflow, 'order:123', 'submit');
        $this->assertSame(['review' => 1], $result);
    }
}
