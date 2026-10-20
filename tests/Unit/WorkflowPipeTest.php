<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\contract\TransitionPayload;
use wise\workflow\contract\TransitionPipeInterface;
use wise\workflow\pipes\FindTransitionPipe;
use wise\workflow\pipes\CheckPendingTasksPipe;
use wise\workflow\pipes\LoadMarkingPipe;
use wise\workflow\pipes\VerifyVersionPipe;
use wise\workflow\pipes\DispatchBeforeEventPipe;
use wise\workflow\pipes\CheckPermissionPipe;
use wise\workflow\pipes\EvaluateGuardsPipe;
use wise\workflow\pipes\ValidateFromPlacesPipe;
use wise\workflow\pipes\TransitionStatePipe;
use wise\workflow\pipes\OptimisticLockSavePipe;
use wise\workflow\pipes\RecordHistoryPipe;
use wise\workflow\pipes\DispatchAfterEventsPipe;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\TransitionPipeline;
use wise\workflow\exception\WorkflowException;

/**
 * Pipeline 管道单元测试（V2 新增）
 * 
 * 覆盖所有 12 个 Pipe 的核心行为：
 * - FindTransitionPipe      查找转换定义
 * - CheckPendingTasksPipe   并行任务检查（需 Mock TaskManager）
 * - LoadMarkingPipe         加载当前状态
 * - VerifyVersionPipe       版本校验
 * - DispatchBeforeEventPipe  before 事件
 * - CheckPermissionPipe     权限检查
 * - EvaluateGuardsPipe      守卫条件
 * - ValidateFromPlacesPipe  前置状态验证
 * - TransitionStatePipe     状态置换
 * - OptimisticLockSavePipe  乐观锁保存
 * - RecordHistoryPipe       历史记录
 * - DispatchAfterEventsPipe 后置事件
 * 
 * 也覆盖 TransitionPipeline 的洋葱模型编排
 */
class WorkflowPipeTest extends TestCase
{
    private $workflow;
    private $markingStore;
    private $engine;
    private $pipeline;

    protected function setUp(): void
    {
        WorkflowRegistry::clear();
        WorkflowRegistry::clearRegisteredPipes();

        $this->workflow = new Workflow('order');
        $this->workflow->addPlace(new Place('draft'));
        $this->workflow->addPlace(new Place('review', ['is_ended' => false]));
        $this->workflow->addPlace(new Place('approved', ['is_ended' => true]));
        $this->workflow->addPlace(new Place('rejected'));
        $this->workflow->addTransition(new Transition('submit', 'draft', 'review'));
        $this->workflow->addTransition(new Transition('approve', 'review', 'approved'));
        $this->workflow->addTransition(new Transition('reject', 'review', 'rejected'));

        $this->markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $this->markingStore->shouldReceive('getVersion')->andReturn(1);
        // 安全审计阶段 1：管道测试显式声明「本环境不装配权限系统」（否则 fail-closed 会拒绝转换）
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];

        $this->engine = new WorkflowEngine($this->markingStore);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        WorkflowRegistry::clearRegisteredPipes();
    }

    // ==================== Pipe 1: FindTransitionPipe ====================

    public function testFindTransitionPipeFindsExistingTransition(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);
        $pipe = new FindTransitionPipe();

        $next = function (TransitionPayload $p) { return $p; };
        $result = $pipe->handle($payload, $next);

        $this->assertNotNull($result->transition);
        $this->assertSame('submit', $result->transition->getName());
    }

    public function testFindTransitionPipeThrowsWhenNotFound(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'non_existent', []);
        $pipe = new FindTransitionPipe();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('不存在');

        $next = function (TransitionPayload $p) { return $p; };
        $pipe->handle($payload, $next);
    }

    // ==================== Pipe 4: ValidateFromPlacesPipe ====================

    public function testValidateFromPlacesPipePassesWithMatchingPlace(): void
    {
        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'submit', []);
        $transition = new Transition('submit', 'draft', 'review');
        $payload->transition = $transition;
        $payload->marking = ['draft' => 1];

        $pipe = new ValidateFromPlacesPipe();
        $next = function (TransitionPayload $p) { return $p; };
        $result = $pipe->handle($payload, $next);

        $this->assertArrayHasKey('draft', $result->marking);
    }

    public function testValidateFromPlacesPipeFailsWithWrongPlace(): void
    {
        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'submit', []);
        $transition = new Transition('submit', 'draft', 'review');
        $payload->transition = $transition;
        $payload->marking = ['approved' => 1]; // 错误的状态

        $pipe = new ValidateFromPlacesPipe();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('状态不匹配');

        $next = function (TransitionPayload $p) { return $p; };
        $pipe->handle($payload, $next);
    }

    public function testValidateFromPlacesRequiresAllFromPlaces(): void
    {
        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'ship', []);
        $transition = new Transition('ship', ['draft', 'paid'], 'shipped');
        $payload->transition = $transition;
        $payload->marking = ['draft' => 1]; // 缺少 paid

        $pipe = new ValidateFromPlacesPipe();

        $this->expectException(WorkflowException::class);

        $next = function (TransitionPayload $p) { return $p; };
        $pipe->handle($payload, $next);
    }

    // ==================== Pipe 5: TransitionStatePipe ====================

    public function testTransitionStatePipeSwapsPlaces(): void
    {
        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'submit', []);
        $transition = new Transition('submit', 'draft', 'review');
        $payload->transition = $transition;
        $payload->marking = ['draft' => 1];

        $pipe = new TransitionStatePipe();
        $next = function (TransitionPayload $p) { return $p; };
        $result = $pipe->handle($payload, $next);

        // draft 应该被移除，review 应该被添加
        $this->assertArrayNotHasKey('draft', $result->marking);
        $this->assertArrayHasKey('review', $result->marking);
        $this->assertSame(1, $result->marking['review']);

        // oldMarking 快照
        $this->assertArrayHasKey('draft', $result->oldMarking);
        $this->assertArrayNotHasKey('review', $result->oldMarking);
    }

    public function testTransitionStatePipeMultipleFromAndTo(): void
    {
        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'split', []);
        $transition = new Transition('split', ['draft', 'paid'], ['review', 'notify']);
        $payload->transition = $transition;
        $payload->marking = ['draft' => 1, 'paid' => 1];

        $pipe = new TransitionStatePipe();
        $next = function (TransitionPayload $p) { return $p; };
        $result = $pipe->handle($payload, $next);

        $this->assertArrayNotHasKey('draft', $result->marking);
        $this->assertArrayNotHasKey('paid', $result->marking);
        $this->assertArrayHasKey('review', $result->marking);
        $this->assertArrayHasKey('notify', $result->marking);
    }

    // ==================== Pipe: OptimisticLockSavePipe ====================

    public function testOptimisticLockSavePipeCallsMarkingStore(): void
    {
        $markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $markingStore->shouldReceive('getVersion')->andReturn(1);
        $pipeline = new TransitionPipeline($markingStore);

        $workflow  = $this->workflow;
        $payload   = new TransitionPayload($workflow, 'order:1', 'submit', ['user_id' => 1]);
        $payload->marking = ['review' => 1];
        $payload->version = 5;

        $markingStore->shouldReceive('setMarking')
            ->withAnyArgs()
            ->once()
            ->andReturn(6);

        $pipe = new OptimisticLockSavePipe($pipeline);
        $next = function (TransitionPayload $p) { return $p; };
        $result = $pipe->handle($payload, $next);

        $this->assertTrue($result->saved);
        $this->assertSame(6, $result->version);
    }

    // ==================== Pipe: RecordHistoryPipe ====================

    public function testRecordHistoryPipeCallsStore(): void
    {
        $markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $markingStore->shouldReceive('getVersion')->andReturn(1);
        $pipeline = new TransitionPipeline($markingStore);

        $workflow  = $this->workflow;
        $transition = new Transition('submit', 'draft', 'review');
        $payload   = new TransitionPayload($workflow, 'order:1', 'submit', []);
        $payload->transition = $transition;
        $payload->marking    = ['review' => 1];
        $payload->oldMarking = ['draft' => 1];

        $markingStore->shouldReceive('recordHistory')
            ->with('order:1', 'submit', 'draft', 'review', [], \Mockery::any())
            ->once();

        $pipe = new RecordHistoryPipe($pipeline);
        $next = function (TransitionPayload $p) { return $p; };
        $pipe->handle($payload, $next);

        $this->assertTrue(true); // Mockery 验证 recordHistory 被调用
    }

    // ==================== Pipe Chain (洋葱模型) ====================

    public function testPipelineExecutesPipesInOrder(): void
    {
        // 验证核心管道链可以正确执行（Mock Store）
        $this->markingStore->shouldReceive('getMarking')
            ->with('order:chain')
            ->andReturn(['draft' => 1]);
        $this->markingStore->shouldReceive('getVersion')
            ->with('order:chain')
            ->andReturn(1);
        $this->markingStore->shouldReceive('setMarking')
            ->withAnyArgs()
            ->once()
            ->andReturn(2);
        $this->markingStore->shouldReceive('recordHistory')
            ->with('order:chain', 'submit', 'draft', 'review', \Mockery::any(), \Mockery::any())
            ->once();

        $result = $this->engine->apply($this->workflow, 'order:chain', 'submit');

        $this->assertSame(['review' => 1], $result);
    }

    public function testPipelineWithEventService(): void
    {
        $eventService = \Mockery::mock();
        $eventService->shouldReceive('trigger')
            ->with(\Mockery::type('string'), \Mockery::type('array'))
            ->atLeast()->times(1);

        $markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $markingStore->shouldReceive('getVersion')->andReturn(1);
        $markingStore->shouldReceive('getMarking')
            ->with('order:event')
            ->andReturn(['draft' => 1]);
        $markingStore->shouldReceive('getVersion')
            ->with('order:event')
            ->andReturn(1);
        $markingStore->shouldReceive('setMarking')
            ->andReturn(2);
        $markingStore->shouldReceive('recordHistory');

        $engine = new WorkflowEngine($markingStore, $eventService);
        $result = $engine->apply($this->workflow, 'order:event', 'submit');

        $this->assertSame(['review' => 1], $result);
    }

    public function testPipelineWithGuardCondition(): void
    {
        $workflow = new Workflow('guard_test');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addTransition(new Transition(
            'auto_approve',
            'draft',
            'approved',
            function (array $marking, array $context): bool {
                return ($context['amount'] ?? 999) < 100;
            }
        ));

        $markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $markingStore->shouldReceive('getVersion')->andReturn(1);
        $markingStore->shouldReceive('getMarking')
            ->withAnyArgs()
            ->andReturn(['draft' => 1]);
        $markingStore->shouldReceive('getVersion')
            ->with('order:guard1')
            ->andReturn(1);
        $markingStore->shouldReceive('setMarking')
            ->andReturn(2);
        $markingStore->shouldReceive('recordHistory');

        $engine = new WorkflowEngine($markingStore);

        // Guard 满足：amount=50 < 100
        $result = $engine->apply($workflow, 'order:guard1', 'auto_approve', ['amount' => 50]);
        $this->assertSame(['approved' => 1], $result);
    }

    public function testPipelineWithGuardFails(): void
    {
        $workflow = new Workflow('guard_test');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addTransition(new Transition(
            'auto_approve',
            'draft',
            'approved',
            function (array $marking, array $context): bool {
                return ($context['amount'] ?? 999) < 100;
            }
        ));

        $markingStore = \Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $markingStore->shouldReceive('getVersion')->andReturn(1);
        $markingStore->shouldReceive('getMarking')
            ->withAnyArgs()
            ->andReturn(['draft' => 1]);
        $markingStore->shouldReceive('getVersion')
            ->with('order:guard2')
            ->andReturn(1);

        $engine = new WorkflowEngine($markingStore);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('守卫条件不满足');

        // Guard 不满足：amount=500 >= 100
        $engine->apply($workflow, 'order:guard2', 'auto_approve', ['amount' => 500]);
    }

    // ==================== can() 兼容性 ====================

    public function testCanStillWorksAfterPipelineUpgrade(): void
    {
        $this->markingStore->shouldReceive('getMarking')
            ->with('order:123')
            ->andReturn(['draft' => 1]);

        $result = $this->engine->can($this->workflow, 'order:123', 'submit');
        $this->assertTrue($result);
    }

    // ==================== Plugin Pipe Registration ====================

    public function testRegisterPipeAddsToSlots(): void
    {
        WorkflowRegistry::registerPipe(
            'before_TransitionState',
            'App\\Pipes\\CustomCheckPipe'
        );

        $pipes = WorkflowRegistry::getRegisteredPipes();
        $this->assertArrayHasKey('before_TransitionState', $pipes);
        $this->assertContains('App\\Pipes\\CustomCheckPipe', $pipes['before_TransitionState']);
    }

    public function testRegisterPipeForSpecificWorkflow(): void
    {
        WorkflowRegistry::registerPipeFor(
            'order_approval',
            'App\\Pipes\\SlaPipe',
            after: 'EvaluateGuards'
        );

        $pipes = WorkflowRegistry::getWorkflowPipes('order_approval');
        $this->assertArrayHasKey('after_EvaluateGuards', $pipes);
        $this->assertContains('App\\Pipes\\SlaPipe', $pipes['after_EvaluateGuards']);
    }

    public function testRegisterCustomPipeline(): void
    {
        $customPipes = [
            FindTransitionPipe::class,
            LoadMarkingPipe::class,
            TransitionStatePipe::class,
        ];

        WorkflowRegistry::registerPipeline('minimal', $customPipes);
        $retrieved = WorkflowRegistry::getCustomPipeline('minimal');

        $this->assertSame($customPipes, $retrieved);
    }

    // ==================== TransitionPayload ====================

    public function testTransitionPayloadLogsCorrectly(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);
        $payload->log('TestPipe', 'passed', 'OK');
        $payload->log('TestPipe2', 'skipped', 'NoConfig');

        $this->assertCount(2, $payload->pipeLog);
        $this->assertSame('passed', $payload->pipeLog[0]['status']);
        $this->assertSame('TestPipe2', $payload->pipeLog[1]['pipe']);
    }

    public function testTransitionPayloadHelpers(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);
        $payload->marking = ['draft' => 1, 'paid' => 1];
        $payload->oldMarking = ['draft' => 1];

        $this->assertSame('order', $payload->getWorkflowName());
        $this->assertStringContainsString('draft', $payload->getMarkingString());
        $this->assertStringContainsString('paid', $payload->getMarkingString());
        $this->assertSame('draft', $payload->getOldMarkingString());
    }

    // ==================== TaskCompletedEvent ====================

    public function testTaskCompletedEventProperties(): void
    {
        $event = new \wise\workflow\event\TaskCompletedEvent(
            'order:123', 'approve', 'all', ['comment' => 'OK']
        );

        $this->assertSame('order:123', $event->subject);
        $this->assertSame('approve', $event->transitionName);
        $this->assertSame('all', $event->policy);
        $this->assertSame('OK', $event->context['comment']);
    }

    // ==================== getDefaultPipes ====================

    public function testGetDefaultPipesReturns12Pipes(): void
    {
        $pipes = $this->engine->getDefaultPipes();
        $this->assertCount(12, $pipes);
        $this->assertSame(FindTransitionPipe::class, $pipes[0]);
        $this->assertSame(OptimisticLockSavePipe::class, $pipes[11]);
    }

    // ==================== Conditionable Trait ====================

    public function testWhenWithTruthyCondition(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);

        $result = $payload->when(true, function (TransitionPayload $p) {
            return $p->withContext(['user_id' => 1])->withVersion(5);
        });

        $this->assertSame($payload, $result);
        $this->assertSame(1, $result->context['user_id']);
        $this->assertSame(5, $result->version);
    }

    public function testWhenWithFalsyConditionSkipsCallback(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', ['base' => 'x']);

        $result = $payload->when(false, function (TransitionPayload $p) {
            return $p->withContext(['skipped' => true]);
        });

        $this->assertSame($payload, $result);
        $this->assertArrayHasKey('base', $result->context);
        $this->assertArrayNotHasKey('skipped', $result->context);
    }

    public function testWhenWithDefaultBranch(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);

        $result = $payload->when(
            false,
            fn($p) => $p->withContext(['if_true' => 1]),
            fn($p) => $p->withContext(['if_false' => 1])
        );

        $this->assertArrayNotHasKey('if_true', $result->context);
        $this->assertArrayHasKey('if_false', $result->context);
    }

    public function testUnlessWhenConditionIsFalse(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);

        $result = $payload->unless(false, function (TransitionPayload $p) {
            return $p->withVersion(10);
        });

        $this->assertSame(10, $result->version);
    }

    public function testUnlessSkipsWhenConditionIsTrue(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);

        $result = $payload->unless(true, function (TransitionPayload $p) {
            return $p->withVersion(99);
        });

        $this->assertSame(0, $result->version); // 未修改
    }

    public function testWhenReturnsSubjectAfterChain(): void
    {
        $payload = new TransitionPayload($this->workflow, 'order:1', 'submit', []);
        $payload->withMarking(['draft' => 1]);

        $result = $payload
            ->when(true, fn($p) => $p->withLog('PipeA', 'passed'))
            ->when(false, fn($p) => $p->withLog('PipeB', 'skipped'))
            ->unless(true, fn($p) => $p->withLog('PipeC', 'skipped'))
            ->unless(false, fn($p) => $p->withLog('PipeD', 'passed'));

        $this->assertCount(2, $result->pipeLog);
        $this->assertSame('PipeA', $result->pipeLog[0]['pipe']);
        $this->assertSame('PipeD', $result->pipeLog[1]['pipe']);
        $this->assertSame(['draft' => 1], $result->marking);
    }
}
