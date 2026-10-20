<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use Mockery;
use PHPUnit\Framework\TestCase;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\contract\PermissionServiceInterface;
use wise\workflow\exception\WorkflowException;
use wise\workflow\guard\ExpressionGuard;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;

/**
 * 安全审计阶段 1 的回归测试
 *
 * 覆盖（每条都对应一个此前的「默认不安全」行为）：
 * - B-1 未注入权限服务时 fail-closed（此前静默放行）
 * - B-1 permission.allow_all 显式放行仍然可用
 * - B-2 调用方伪造 context['auto_sla'] 不再能跳过权限校验（此前可以）
 * - B-2 引擎注入的系统触发标记仍然能跳过（SLA 路径不被误伤）
 * - B-3 守卫表达式未知标识符不再恒为真（严格模式报错 / 非严格模式为 false）
 * - F-3 乐观锁冲突时不会写历史（此前副作用先于落库提交、重试还会重放）
 */
class SecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        // 默认走包默认配置（permission.allow_all = false，即 fail-closed）
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => false]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__WF_CONFIG_OVERRIDE']);
        // 白名单是静态状态，必须复位，避免污染后续用例
        ExpressionGuard::setAllowedContextKeys(null);
        Mockery::close();
    }

    private function workflow(): Workflow
    {
        $workflow = new Workflow('order');
        $workflow->addPlace(new Place('draft'));
        $workflow->addPlace(new Place('review'));
        $workflow->addPlace(new Place('approved'));
        $workflow->addTransition(new Transition('submit', 'draft', 'review'));
        return $workflow;
    }

    /**
     * @return \Mockery\MockInterface|MarkingStoreInterface
     */
    private function markingStore(string $subject)
    {
        $store = Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $store->shouldReceive('getVersion')->andReturn(1);
        $store->shouldReceive('getMarking')->with($subject)->andReturn(['draft' => 1]);
        $store->shouldReceive('setMarking')->andReturn(2);
        return $store;
    }

    // ==================== B-1：权限服务缺失 ====================

    public function testApplyIsRejectedWhenNoPermissionServiceIsInjected(): void
    {
        $engine = new WorkflowEngine($this->markingStore('order:1'), null, null);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('未注入权限服务');
        $engine->apply($this->workflow(), 'order:1', 'submit');
    }

    public function testApplyStaysAllowedWhenAllowAllIsExplicitlyConfigured(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];

        $engine = new WorkflowEngine($this->markingStore('order:2'), null, null);
        $this->assertSame(['review' => 1], $engine->apply($this->workflow(), 'order:2', 'submit'));
    }

    // ==================== B-2：auto_sla 不可伪造 ====================

    public function testCallerSuppliedAutoSlaCannotSkipPermissionCheck(): void
    {
        $permission = new RecordingPermission(false); // 拒绝一切，但记录是否被调用
        $engine     = new WorkflowEngine($this->markingStore('order:3'), null, $permission);

        try {
            $engine->apply($this->workflow(), 'order:3', 'submit', ['auto_sla' => '1']);
            $this->fail('伪造 auto_sla 应当被权限校验拦下');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('权限校验失败', $e->getMessage());
        }

        $this->assertTrue($permission->called, '权限服务必须被调用——说明调用方的 auto_sla 已被剥离');
    }

    public function testEngineInjectedSystemTriggerStillSkipsPermissionCheck(): void
    {
        $permission = new RecordingPermission(false);
        $engine     = new WorkflowEngine($this->markingStore('order:4'), null, $permission);

        // 第 5 个参数由引擎/调度侧提供，调用方无法设置
        $result = $engine->apply($this->workflow(), 'order:4', 'submit', [], 'auto_reject');

        $this->assertSame(['review' => 1], $result);
        $this->assertFalse($permission->called, '系统触发的转换应跳过用户权限校验（SLA 路径）');
    }

    // ==================== B-3：守卫表达式 fail-closed ====================

    public function testUnknownIdentifierInGuardThrowsInStrictMode(): void
    {
        $guard = new ExpressionGuard();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('守卫表达式求值失败');
        $guard->evaluate('vip', [], []);
    }

    public function testUnknownIdentifierIsFalsyInNonStrictMode(): void
    {
        $guard = new ExpressionGuard();
        $guard->setStrict(false);

        $this->assertFalse($guard->evaluate('vip', [], []));
    }

    public function testQuotedStringComparisonStillWorks(): void
    {
        $guard = new ExpressionGuard();

        $this->assertTrue($guard->evaluate("context.role == 'admin'", [], ['role' => 'admin']));
        $this->assertFalse($guard->evaluate("context.role == 'admin'", [], ['role' => 'member']));
    }

    // ==================== F-3：冲突时不得先写副作用 ====================

    public function testOptimisticLockConflictDoesNotWriteHistory(): void
    {
        $subject    = 'order:5';
        $conflict   = new WorkflowException('乐观锁冲突：版本不匹配，请重试');
        $store      = Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $store->shouldReceive('getVersion')->andReturn(1);
        $store->shouldReceive('getMarking')->with($subject)->andReturn(['draft' => 1]);
        $store->shouldReceive('setMarking')->andThrow($conflict);
        $store->shouldReceive('recordHistory')->never();

        $engine = new WorkflowEngine($store, null, new RecordingPermission(true));
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];

        try {
            $engine->apply($this->workflow(), $subject, 'submit');
            $this->fail('乐观锁冲突应当抛出');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('乐观锁冲突', $e->getMessage());
        }
    }

    public function testSuccessfulTransitionStillRecordsHistoryAfterSave(): void
    {
        $subject = 'order:6';
        $store   = Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $store->shouldReceive('getVersion')->andReturn(1);
        $store->shouldReceive('getMarking')->with($subject)->andReturn(['draft' => 1]);
        $store->shouldReceive('setMarking')->andReturn(2);
        $store->shouldReceive('recordHistory')->once();

        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];
        $engine = new WorkflowEngine($store, null, null);

        $this->assertSame(['review' => 1], $engine->apply($this->workflow(), $subject, 'submit'));
    }

    // ==================== D-1：rollback / delegate 不再免检 ====================

    public function testRollbackIsRejectedWithoutPermissionService(): void
    {
        $engine = new WorkflowEngine($this->markingStore('order:7'), null, null);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('未注入权限服务');
        $engine->rollback($this->workflow(), 'order:7', []);
    }

    public function testRollbackConsultsPermissionServiceAndDenies(): void
    {
        $permission = new RecordingPermission(false);
        $engine     = new WorkflowEngine($this->markingStore('order:8'), null, $permission);

        try {
            $engine->rollback($this->workflow(), 'order:8', []);
            $this->fail('权限服务拒绝时回滚不应继续');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('无权执行 __rollback__', $e->getMessage());
        }

        $this->assertTrue($permission->called, '回滚必须经过权限判定（此前完全绕过管道与权限）');
    }

    public function testRollbackAcceptsOperationAuthorizer(): void
    {
        $authorizer = new RecordingOperationAuthorizer(true);
        $engine     = new WorkflowEngine(
            $this->markingStore('order:9'),
            null,
            $authorizer,
            $this->historyModelWithoutRecords()
        );

        try {
            $engine->rollback($this->workflow(), 'order:9', []);
            $this->fail('没有历史记录时应抛出');
        } catch (WorkflowException $e) {
            // 已经越过授权点，失败原因是「无历史记录」而不是权限
            $this->assertStringNotContainsString('权限校验失败', $e->getMessage());
            $this->assertStringContainsString('没有可回滚的历史记录', $e->getMessage());
        }

        $this->assertSame('__rollback__', $authorizer->lastOperation);
    }

    public function testDelegateIsRejectedWithoutPermissionService(): void
    {
        $engine = new WorkflowEngine($this->markingStore('order:10'), null, null);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('未注入权限服务');
        $engine->delegate($this->workflow(), 'order:10', 202);
    }

    // ==================== D-2：撤销待办必须授权 ====================

    public function testCancelTasksIsDeniedByPermissionService(): void
    {
        $engine = new WorkflowEngine($this->markingStore('order:11'), null, new RecordingPermission(false));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('无权执行 __cancel_tasks__');
        $engine->cancelTasks('order:11', 'approve', 101);
    }

    // ==================== F-1：系统触发留下操作者标记 ====================

    public function testSystemTriggerRecordsOperatorMarkerInHistory(): void
    {
        $subject  = 'order:12';
        $captured = null;
        $store    = Mockery::mock(MarkingStoreInterface::class)->shouldIgnoreMissing();
        $store->shouldReceive('getVersion')->andReturn(1);
        $store->shouldReceive('getMarking')->with($subject)->andReturn(['draft' => 1]);
        $store->shouldReceive('setMarking')->andReturn(2);
        $store->shouldReceive('recordHistory')->withArgs(function (...$args) use (&$captured) {
            $captured = $args[4] ?? [];
            return true;
        })->once();

        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];
        $engine = new WorkflowEngine($store, null, null);

        $engine->apply($this->workflow(), $subject, 'submit', [], 'auto_reject');

        $this->assertSame('system:auto_reject', $captured['operator'] ?? null, '历史上下文应记录系统触发来源');
        $this->assertSame('auto_reject', $captured['auto_sla'] ?? null);
    }

    // ==================== B-3：守卫上下文白名单 ====================

    public function testGuardWhitelistBlocksUnlistedContextKey(): void
    {
        ExpressionGuard::setAllowedContextKeys(['amount']);
        $guard = new ExpressionGuard();

        $this->assertTrue($guard->evaluate('context.amount > 10', [], ['amount' => 50]));
        $this->assertFalse(
            $guard->evaluate("context.role == 'admin'", [], ['role' => 'admin']),
            '未列入白名单的键必须解析为 null（条件为假），而不是读取调用方数据'
        );
    }

    private function historyModelWithoutRecords()
    {
        $model = Mockery::mock(\wise\workflow\model\WorkflowHistory::class)->shouldIgnoreMissing();
        $model->shouldReceive('where')->andReturnSelf();
        $model->shouldReceive('order')->andReturnSelf();
        $model->shouldReceive('find')->andReturn(null);
        return $model;
    }

    // ==================== C-1：user_id 必须可信 ====================

    public function testApplyStripsCallerSuppliedUserId(): void
    {
        $permission = new ContextCapturingPermission(false);
        $engine     = new WorkflowEngine($this->markingStore('order:13'), null, $permission);

        try {
            $engine->apply($this->workflow(), 'order:13', 'submit', ['user_id' => 999]);
        } catch (WorkflowException $e) {
            // 权限服务拒绝即抛出，这里只关心它收到的上下文
        }

        $this->assertArrayNotHasKey('user_id', $permission->context, '不得保留调用方传入的 user_id');
    }

    public function testApplyInjectsTrustedPrincipalFromResolver(): void
    {
        $permission = new ContextCapturingPermission(false);
        $engine     = new WorkflowEngine($this->markingStore('order:14'), null, $permission);
        $engine->setPrincipalResolver(new FixedPrincipalResolver(42));

        try {
            $engine->apply($this->workflow(), 'order:14', 'submit', ['user_id' => 999]);
        } catch (WorkflowException $e) {
        }

        $this->assertSame(42, $permission->context['user_id'] ?? null, '只认主体解析器给出的可信 ID');
        $this->assertSame('order:14', $permission->context['subject'] ?? null);
    }

    // ==================== C-1：实例归属 ====================

    public function testOwnershipDenialBlocksTransition(): void
    {
        $engine = new WorkflowEngine(
            $this->markingStore('order:15'),
            null,
            new \wise\workflow\permission\AllowAllPermission()
        );
        $engine->setOwnershipService(new FixedOwnership(false));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('无权访问工作流实例');
        $engine->apply($this->workflow(), 'order:15', 'submit');
    }

    public function testOwnershipDenialBlocksRollbackEvenWhenPermissionAllowsAll(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];
        $engine = new WorkflowEngine($this->markingStore('order:16'), null, null);
        $engine->setOwnershipService(new FixedOwnership(false));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('无权访问工作流实例');
        $engine->rollback($this->workflow(), 'order:16', []);
    }

    public function testAnonymousCannotPassOwnershipCheck(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];
        $engine = new WorkflowEngine($this->markingStore('order:18'), null, null);
        // 归属服务「全放行」也不应放过匿名主体（无主体解析器 → user_id = 0）
        $engine->setOwnershipService(new FixedOwnership(true));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('无权访问工作流实例');
        $engine->apply($this->workflow(), 'order:18', 'submit');
    }

    public function testOwnershipAllowsOwningPrincipal(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['permission' => ['allow_all' => true]];
        $engine = new WorkflowEngine($this->markingStore('order:17'), null, null);
        $engine->setPrincipalResolver(new FixedPrincipalResolver(42));
        $engine->setOwnershipService(new FixedOwnership(true));

        $this->assertSame(['review' => 1], $engine->apply($this->workflow(), 'order:17', 'submit'));
    }

    // ==================== E-2：响应字段裁剪 ====================

    public function testResponseSanitizerStripsInternalPolicyAndOperatorFields(): void
    {
        $payload = [
            'subject'    => 'order:1',
            'ip'         => '203.0.113.7',
            'user_agent' => 'curl/8',
            'transitions' => [
                ['name' => 'submit', 'guard_expression' => 'context.amount > 10'],
            ],
            'metadata' => ['sla_config' => ['review' => ['timeout' => 60]]],
        ];

        $clean = \wise\workflow\support\ResponseSanitizer::sanitize($payload);

        $this->assertSame('order:1', $clean['subject'], '业务字段必须保留');
        $this->assertArrayNotHasKey('ip', $clean);
        $this->assertArrayNotHasKey('user_agent', $clean);
        $this->assertArrayNotHasKey('guard_expression', $clean['transitions'][0], '嵌套的守卫表达式同样要剔除');
        $this->assertArrayNotHasKey('sla_config', $clean['metadata']);
    }

    public function testResponseSanitizerKeepsFieldsWhenRevealEnabled(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['response' => ['reveal_sensitive_fields' => true]];

        $clean = \wise\workflow\support\ResponseSanitizer::sanitize(['ip' => '203.0.113.7']);

        $this->assertSame('203.0.113.7', $clean['ip'], '显式开启 reveal 后不再裁剪（旧行为逃生口）');
    }

    public function testCallerSuppliedOperatorMarkerIsStripped(): void
    {
        $permission = new ContextCapturingPermission(false);
        $engine     = new WorkflowEngine($this->markingStore('order:19'), null, $permission);

        try {
            $engine->apply($this->workflow(), 'order:19', 'submit', ['operator' => 'system:auto_reject']);
        } catch (WorkflowException $e) {
        }

        $this->assertArrayNotHasKey('operator', $permission->context, '调用方不得伪造系统触发标记');
    }
}

/**
 * 记录最后一次操作名的操作级授权桩
 */
class RecordingOperationAuthorizer implements \wise\workflow\contract\OperationAuthorizerInterface
{
    public ?string $lastOperation = null;

    public function __construct(private bool $allow)
    {
    }

    public function authorizeOperation(string $operation, string $subject, array $context): bool
    {
        $this->lastOperation = $operation;
        return $this->allow;
    }
}

/**
 * 记录 check() 收到的上下文
 */
class ContextCapturingPermission implements \wise\workflow\contract\PermissionServiceInterface
{
    public array $context = [];

    public function __construct(private bool $allow)
    {
    }

    public function check(\wise\workflow\model\Transition $transition, array $context): bool
    {
        $this->context = $context;
        return $this->allow;
    }
}

/**
 * 固定返回的主体解析桩
 */
class FixedPrincipalResolver implements \wise\workflow\contract\PrincipalResolverInterface
{
    public function __construct(private int $userId)
    {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}

/**
 * 固定归属结论的归属服务桩
 */
class FixedOwnership implements \wise\workflow\contract\SubjectOwnershipInterface
{
    public function __construct(private bool $owned)
    {
    }

    public function isOwnedBy(string $subject, int $userId, array $context = []): bool
    {
        return $this->owned;
    }
}

/**
 * 记录是否被调用的权限服务桩
 */
class RecordingPermission implements PermissionServiceInterface
{
    public bool $called = false;

    public function __construct(private bool $allow)
    {
    }

    public function check(Transition $transition, array $context): bool
    {
        $this->called = true;
        return $this->allow;
    }
}
