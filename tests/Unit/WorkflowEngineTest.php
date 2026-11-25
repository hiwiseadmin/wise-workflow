<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
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

        $this->markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $this->markingStore->shouldReceive('getVersion')->andReturn(1);

        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];

        $this->engine = new WorkflowEngine($this->markingStore);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }


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
        $multiWorkflow = new Workflow('multi');
        $multiWorkflow->addPlace(new Place('draft'));
        $multiWorkflow->addPlace(new Place('paid'));
        $multiWorkflow->addPlace(new Place('shipped'));
        $multiWorkflow->addTransition(new Transition('ship', ['draft', 'paid'], 'shipped'));

        $multiEngine = new WorkflowEngine($this->markingStore);

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


    public function testApplyExecutesTransitionSuccessfully(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->withAnyArgs()
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs()
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
            ->withAnyArgs()
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs()
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
            ->withAnyArgs()
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs()
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
            ->withAnyArgs()
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs()
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
        $this->expectExceptionMessage('守卫条件不满足 [auto_approve]');

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
        $this->expectExceptionMessage("转换 [non_existent] 在工作流 [order] 中不存在");

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
            ->withAnyArgs()
            ->once();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs()
            ->once();

        $result = $this->engine->apply($this->workflow, 'order:123', 'approve', $context);
        $this->assertSame(['approved' => 1], $result);
    }


    public function testGetEnabledTransitionsReturnsAvailableTransitions(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');

        $this->assertCount(1, $enabled);
        $this->assertSame('submit', $enabled[0]->getName());
    }

    public function testGetEnabledTransitionsWithGuardConditionMet(): void
    {
        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->workflow, 'order:123');
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
            ->withAnyArgs()
            ->once();

        $this->engine->setMarking('order:123', $marking, $context);
        $this->assertTrue(true);
    }


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
            ->withAnyArgs()
            ->once();

        $this->engine->init($workflow, $subject);
        $this->assertTrue(true);
    }

    public function testInitSkipsWhenAlreadyInitialized(): void
    {
        $workflow = new Workflow('order', [], [], 'draft');
        $workflow->addPlace(new Place('draft'));

        $subject = 'order:existing';

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldNotReceive('setMarking');

        $this->engine->init($workflow, $subject);
        $this->assertTrue(true);
    }

    public function testInitThrowsExceptionWhenNoInitialPlace(): void
    {
        $workflow = new Workflow('order');

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("工作流 [order] 未定义初始状态");

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
            ->withAnyArgs()
            ->once();

        $this->engine->init($workflow, 'order:ctx1', $context);
        $this->assertTrue(true);
    }


    public function testCompleteWorkflowChain(): void
    {
        $subject = 'order:chain';

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->withAnyArgs();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs();

        $result = $this->engine->apply($this->workflow, $subject, 'submit');
        $this->assertSame(['review' => 1], $result);

        \Mockery::close();
        $this->markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $this->markingStore->shouldReceive('getVersion')->andReturn(1);
        $this->engine = new WorkflowEngine($this->markingStore);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['review' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->withAnyArgs();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs();

        $result = $this->engine->apply($this->workflow, $subject, 'approve');
        $this->assertSame(['approved' => 1], $result);
    }


    /**
     * 安全审计阶段 1：权限服务必须实现 PermissionServiceInterface::check()。
     * 原用例用「空 mock（没有任何方法）」并断言转换成功，等于把「服务不可用即放行」固化为预期。
     */
    public function testEngineWithPermissionService(): void
    {
        $permissionService = \Mockery::mock(\wise\workflow\contract\PermissionServiceInterface::class);
        $permissionService->shouldReceive('check')->andReturn(true);

        $engine = new WorkflowEngine($this->markingStore, null, $permissionService);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->withAnyArgs();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs();

        $result = $engine->apply($this->workflow, 'order:123', 'submit');
        $this->assertSame(['review' => 1], $result);
    }


    public function testEngineWithEventService(): void
    {
        $eventService = \Mockery::mock();
        $eventService->shouldIgnoreMissing();
        $eventService
            ->shouldReceive('trigger')
            ->with('workflow.order.before.submit', \Mockery::type('array'))
            ->once();

        $engine = new WorkflowEngine($this->markingStore, $eventService);

        $this->markingStore
            ->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $this->markingStore
            ->shouldReceive('setMarking')
            ->withAnyArgs();

        $this->markingStore
            ->shouldReceive('recordHistory')
            ->withAnyArgs();

        $result = $engine->apply($this->workflow, 'order:123', 'submit');
        $this->assertSame(['review' => 1], $result);
    }
}
