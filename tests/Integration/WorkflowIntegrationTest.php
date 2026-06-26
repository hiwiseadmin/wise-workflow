<?php
declare(strict_types=1);

namespace wise\workflow\test\Integration;

use PHPUnit\Framework\TestCase;
use wise\workflow\MarkingStoreInterface;
use wise\workflow\Place;
use wise\workflow\Transition;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\GuardListener;
use wise\workflow\exception\WorkflowException;
use think\facade\Session;

/**
 * 工作流集成测试
 *
 * 模拟完整的工作流场景，测试所有组件的协同工作
 * 使用 Mockery 模拟 MarkingStoreInterface，不依赖真实数据库
 *
 * 场景覆盖：
 * - 简单线性工作流
 * - 分支/合并工作流
 * - 带守卫的工作流
 * - 带权限检查的工作流
 * - 多主体并发工作流
 * - 异常路径
 */
class WorkflowIntegrationTest extends TestCase
{
    /** @var Workflow */
    private $orderWorkflow;

    /** @var \Mockery\MockInterface|MarkingStoreInterface */
    private $store;

    /** @var WorkflowEngine */
    private $engine;

    protected function setUp(): void
    {
        // 构建标准订单审批工作流
        $this->orderWorkflow = new Workflow('order_approval');
        $this->orderWorkflow->addPlace(new Place('draft'));
        $this->orderWorkflow->addPlace(new Place('pending_review'));
        $this->orderWorkflow->addPlace(new Place('pending_manager'));
        $this->orderWorkflow->addPlace(new Place('approved'));
        $this->orderWorkflow->addPlace(new Place('rejected'));
        $this->orderWorkflow->addPlace(new Place('revised'));

        $this->orderWorkflow->addTransition(new Transition('submit', 'draft', 'pending_review'));
        $this->orderWorkflow->addTransition(new Transition('approve', 'pending_review', 'approved'));
        $this->orderWorkflow->addTransition(new Transition('reject', 'pending_review', 'rejected'));
        $this->orderWorkflow->addTransition(new Transition('request_revision', 'pending_review', 'revised'));
        $this->orderWorkflow->addTransition(new Transition('resubmit', 'revised', 'pending_review'));
        $this->orderWorkflow->addTransition(new Transition(
            'escalate',
            'pending_review',
            'pending_manager',
            function (array $marking, array $context): bool {
                return ($context['amount'] ?? 0) > 10000;
            }
        ));
        $this->orderWorkflow->addTransition(new Transition('manager_approve', 'pending_manager', 'approved'));
        $this->orderWorkflow->addTransition(new Transition('manager_reject', 'pending_manager', 'rejected'));

        $this->orderWorkflow->setMetadata([
            'title' => '订单审批流程',
            'description' => '标准企业订单审批工作流',
        ]);

        $this->store = \Mockery::mock(MarkingStoreInterface::class);
        $this->engine = new WorkflowEngine($this->store);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        WorkflowRegistry::clear();
    }

    // ==================== 场景1：简单线性审批流程 ====================

    public function testSimpleApprovalFlow(): void
    {
        $subject = 'order:simple1';

        // draft → submit → pending_review
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['pending_review' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory')
            ->with($subject, 'submit', 'draft', 'pending_review', \Mockery::any());

        $result = $this->engine->apply($this->orderWorkflow, $subject, 'submit');
        $this->assertSame(['pending_review' => 1], $result);

        // 重置 mock
        \Mockery::close();
        $this->store = \Mockery::mock(MarkingStoreInterface::class);
        $this->engine = new WorkflowEngine($this->store);

        // pending_review → approve → approved
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['pending_review' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['approved' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory')
            ->with($subject, 'approve', 'pending_review', 'approved', \Mockery::any());

        $result = $this->engine->apply($this->orderWorkflow, $subject, 'approve');
        $this->assertSame(['approved' => 1], $result);
    }

    // ==================== 场景2：拒绝后重新提交 ====================

    public function testRejectAndResubmitFlow(): void
    {
        $subject = 'order:reject1';

        // draft → request_revision → revised
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['pending_review' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['revised' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory')
            ->with($subject, 'request_revision', 'pending_review', 'revised', \Mockery::any());

        $result = $this->engine->apply($this->orderWorkflow, $subject, 'request_revision');
        $this->assertSame(['revised' => 1], $result);

        // 重置 mock
        \Mockery::close();
        $this->store = \Mockery::mock(MarkingStoreInterface::class);
        $this->engine = new WorkflowEngine($this->store);

        // revised → resubmit → pending_review
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['revised' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['pending_review' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory')
            ->with($subject, 'resubmit', 'revised', 'pending_review', \Mockery::any());

        $result = $this->engine->apply($this->orderWorkflow, $subject, 'resubmit');
        $this->assertSame(['pending_review' => 1], $result);
    }

    // ==================== 场景3：带守卫的大额订单升级 ====================

    public function testEscalateWhenAmountExceedsThreshold(): void
    {
        $subject = 'order:large1';

        // pending_review 状态，金额超过 10000 可以 escalate
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['pending_review' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['pending_manager' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory')
            ->with($subject, 'escalate', 'pending_review', 'pending_manager', \Mockery::any());

        $result = $this->engine->apply(
            $this->orderWorkflow,
            $subject,
            'escalate',
            ['amount' => 50000, 'user_id' => 1]
        );
        $this->assertSame(['pending_manager' => 1], $result);
    }

    public function testEscalateBlockedWhenAmountBelowThreshold(): void
    {
        $subject = 'order:small1';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['pending_review' => 1]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Guard condition failed for transition [escalate]');

        $this->engine->apply(
            $this->orderWorkflow,
            $subject,
            'escalate',
            ['amount' => 5000] // 低于阈值
        );
    }

    // ==================== 场景4：多主体并发 ====================

    public function testMultipleSubjectsIndependentState(): void
    {
        $order1 = 'order:001';
        $order2 = 'order:002';

        // 订单1：draft → submit
        $this->store->shouldReceive('getMarking')
            ->with($order1)
            ->andReturn(['draft' => 1]);
        $this->store->shouldReceive('getMarking')
            ->with($order2)
            ->andReturn(['pending_review' => 1]);

        $this->store->shouldReceive('setMarking')->with($order1, \Mockery::any(), \Mockery::any());
        $this->store->shouldReceive('setMarking')->with($order2, \Mockery::any(), \Mockery::any());
        $this->store->shouldReceive('recordHistory')->with($order1, \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any());
        $this->store->shouldReceive('recordHistory')->with($order2, \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any());

        // 两个订单各自执行独立的转换
        $this->engine->apply($this->orderWorkflow, $order1, 'submit');
        $this->engine->apply($this->orderWorkflow, $order2, 'approve');

        $this->assertTrue(true); // No exception = success
    }

    // ==================== 场景5：can() 与可用转换查询 ====================

    public function testCanCheckBeforeApply(): void
    {
        $subject = 'order:check1';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        // 检查可用性
        $canSubmit = $this->engine->can($this->orderWorkflow, $subject, 'submit');
        $this->assertTrue($canSubmit);

        $canApprove = $this->engine->can($this->orderWorkflow, $subject, 'approve');
        $this->assertFalse($canApprove);

        // 获取所有可用转换
        $enabled = $this->engine->getEnabledTransitions($this->orderWorkflow, $subject);
        $enabledNames = array_map(function ($t) {
            return $t->getName();
        }, $enabled);

        $this->assertContains('submit', $enabledNames);
        $this->assertNotContains('approve', $enabledNames);
        $this->assertNotContains('reject', $enabledNames);
    }

    // ==================== 场景6：终态不可再转换 ====================

    public function testTerminalStateNoTransitions(): void
    {
        $subject = 'order:terminal';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['approved' => 1]);

        $enabled = $this->engine->getEnabledTransitions($this->orderWorkflow, $subject);
        $this->assertEmpty($enabled);
    }

    // ==================== 场景7：WorkflowRegistry 集成 ====================

    public function testRegistryAndEngineIntegration(): void
    {
        WorkflowRegistry::add($this->orderWorkflow);

        $retrieved = WorkflowRegistry::get('order_approval');
        $this->assertSame($this->orderWorkflow, $retrieved);

        // 使用注册表中的工作流执行转换
        $subject = 'order:reg1';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['pending_review' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory');

        $result = $this->engine->apply(
            WorkflowRegistry::get('order_approval'),
            $subject,
            'submit'
        );

        $this->assertSame(['pending_review' => 1], $result);
    }

    // ==================== 场景8：GuardListener 与 Transition 集成 ====================

    public function testGuardListenerWithTransition(): void
    {
        Session::setForTest('user.id', 42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->andReturn(true);

        $guardCallable = GuardListener::create($permissionService);

        $transition = new Transition('secure_approve', 'review', 'approved', $guardCallable);

        $this->assertNotNull($transition->getGuard());
        $this->assertIsCallable($transition->getGuard());

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'secure_approve',
        ];

        $result = ($transition->getGuard())(['review' => 1], $context);
        $this->assertTrue($result);
    }

    // ==================== 场景9：异常处理 ====================

    public function testCannotApplyFromWrongState(): void
    {
        $subject = 'order:wrong_state';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['approved' => 1]);

        // 已审批的订单不能再提交
        $canSubmit = $this->engine->can($this->orderWorkflow, $subject, 'submit');
        $this->assertFalse($canSubmit);
    }

    public function testCannotApplyNonExistentTransition(): void
    {
        $subject = 'order:bad_transition';

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Transition [non_existent] not found in workflow [order_approval]");

        $this->engine->apply($this->orderWorkflow, $subject, 'non_existent');
    }

    // ==================== 场景10：完整初始化 → 执行流程 ====================

    public function testFullInitAndExecuteCycle(): void
    {
        $subject = 'order:lifecycle';

        // 初始化
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn([]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['draft' => 1], \Mockery::any());

        $workflow = new Workflow('lifecycle', [], [], 'draft');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('review'));
        $workflow->addPlace(new Place('done'));
        $workflow->addTransition(new Transition('start', 'draft', 'review'));
        $workflow->addTransition(new Transition('finish', 'review', 'done'));

        $this->engine->init($workflow, $subject);

        // 然后执行转换
        \Mockery::close();
        $this->store = \Mockery::mock(MarkingStoreInterface::class);

        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['draft' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['review' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory');

        $this->engine = new WorkflowEngine($this->store);
        $result = $this->engine->apply($workflow, $subject, 'start');

        $this->assertSame(['review' => 1], $result);
    }

    // ==================== 场景11：复杂分支工作流 ====================

    public function testComplexBranchingWorkflow(): void
    {
        // 创建分支工作流
        $branchWorkflow = new Workflow('complex_branch');
        $branchWorkflow->addPlace(new Place('start'));
        $branchWorkflow->addPlace(new Place('branch_a'));
        $branchWorkflow->addPlace(new Place('branch_b'));
        $branchWorkflow->addPlace(new Place('merge'));
        $branchWorkflow->addPlace(new Place('end'));

        $branchWorkflow->addTransition(new Transition('go_a', 'start', 'branch_a'));
        $branchWorkflow->addTransition(new Transition('go_b', 'start', 'branch_b'));
        $branchWorkflow->addTransition(new Transition('merge_a', 'branch_a', 'merge'));
        $branchWorkflow->addTransition(new Transition('merge_b', 'branch_b', 'merge'));
        $branchWorkflow->addTransition(new Transition('complete', 'merge', 'end'));

        $subject = 'item:branch1';

        // 走分支 A
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['start' => 1]);
        $this->store->shouldReceive('setMarking')
            ->with($subject, ['branch_a' => 1], \Mockery::any());
        $this->store->shouldReceive('recordHistory');

        $engine = new WorkflowEngine($this->store);
        $result = $engine->apply($branchWorkflow, $subject, 'go_a');
        $this->assertSame(['branch_a' => 1], $result);

        // 验证分支 B 不可用
        \Mockery::close();
        $this->store = \Mockery::mock(MarkingStoreInterface::class);
        $this->store->shouldReceive('getMarking')
            ->with($subject)
            ->andReturn(['branch_a' => 1]);

        $engine = new WorkflowEngine($this->store);
        $this->assertFalse($engine->can($branchWorkflow, $subject, 'go_b'));
        $this->assertTrue($engine->can($branchWorkflow, $subject, 'merge_a'));
    }

    // ==================== 场景12：混合守卫条件 ====================

    public function testMixedGuardConditions(): void
    {
        $mixedWorkflow = new Workflow('mixed_guard');
        $mixedWorkflow->addPlace(new Place('open'));
        $mixedWorkflow->addPlace(new Place('processing'));
        $mixedWorkflow->addPlace(new Place('closed'));

        // 无守卫的直接转换
        $mixedWorkflow->addTransition(new Transition('start', 'open', 'processing'));

        // 带多个条件的守卫
        $mixedWorkflow->addTransition(new Transition(
            'close',
            'processing',
            'closed',
            function (array $marking, array $context): bool {
                $hasPayment = ($context['paid'] ?? false) === true;
                $hasApproval = ($context['approved'] ?? false) === true;
                return $hasPayment && $hasApproval;
            }
        ));

        $engine = new WorkflowEngine($this->store);

        // 测试条件不满足
        $this->store->shouldReceive('getMarking')
            ->with('task:mix1')
            ->andReturn(['processing' => 1]);

        $this->assertFalse($engine->can($mixedWorkflow, 'task:mix1', 'close', ['paid' => true]));
        $this->assertFalse($engine->can($mixedWorkflow, 'task:mix1', 'close', ['approved' => true]));
        $this->assertTrue($engine->can($mixedWorkflow, 'task:mix1', 'close', ['paid' => true, 'approved' => true]));
    }
}
