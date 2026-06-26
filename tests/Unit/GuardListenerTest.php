<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use think\facade\Session;
use wise\workflow\GuardListener;

/**
 * GuardListener 权限守卫监听器单元测试
 *
 * 覆盖：
 * - 无权限服务时默认放行
 * - 有权限服务时的检查逻辑
 * - 缺少上下文信息时的回退行为
 * - create() 工厂方法
 * - __invoke() 魔术方法
 */
class GuardListenerTest extends TestCase
{
    protected function setUp(): void
    {
        // 确保每次测试前清理 Session 测试值
        if (class_exists(Session::class) && method_exists(Session::class, 'clearForTest')) {
            Session::clearForTest();
        }
    }

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /** 设置测试用的模拟已登录用户 */
    private function setTestUserId(int $id = 42): void
    {
        if (class_exists(Session::class) && method_exists(Session::class, 'setForTest')) {
            Session::setForTest('user.id', $id);
        }
    }

    // ==================== 无权限服务测试 ====================

    public function testInvokeWithoutPermissionServiceReturnsTrue(): void
    {
        $listener = new GuardListener(null);
        $result = $listener(['draft' => 1], ['transition_name' => 'submit']);
        $this->assertTrue($result);
    }

    public function testInvokeWithoutPermissionServiceIgnoresContext(): void
    {
        $listener = new GuardListener(null);
        $result = $listener(['draft' => 1], []);
        $this->assertTrue($result);
    }

    // ==================== 有权限服务测试 ====================

    public function testInvokeWithPermissionServiceAndValidUser(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->with(42, 'workflow.order.submit')
            ->andReturn(true);

        $listener = new GuardListener($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'submit',
        ];

        $result = $listener(['draft' => 1], $context);
        $this->assertTrue($result);
    }

    public function testInvokeWithPermissionServiceAndPermissionDenied(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->with(42, 'workflow.order.approve')
            ->andReturn(false);

        $listener = new GuardListener($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'approve',
        ];

        $result = $listener(['review' => 1], $context);
        $this->assertFalse($result);
    }

    public function testInvokeWithPermissionServiceButNoUserId(): void
    {
        // 不调用 setTestUserId，Session 将返回 null
        $permissionService = \Mockery::mock();

        $listener = new GuardListener($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'submit',
        ];

        $result = $listener(['draft' => 1], $context);
        $this->assertFalse($result);
    }

    // ==================== 缺少上下文信息测试 ====================

    public function testInvokeWithPermissionServiceButNoWorkflowName(): void
    {
        $permissionService = \Mockery::mock();

        $listener = new GuardListener($permissionService);

        $context = [
            'transition_name' => 'submit',
        ];

        $result = $listener(['draft' => 1], $context);
        $this->assertTrue($result);
    }

    public function testInvokeWithPermissionServiceButNoTransitionName(): void
    {
        $permissionService = \Mockery::mock();

        $listener = new GuardListener($permissionService);

        $context = [
            'workflow_name' => 'order',
        ];

        $result = $listener(['draft' => 1], $context);
        $this->assertTrue($result);
    }

    public function testInvokeWithPermissionServiceAndEmptyContext(): void
    {
        $permissionService = \Mockery::mock();

        $listener = new GuardListener($permissionService);

        $result = $listener(['draft' => 1], []);
        $this->assertTrue($result);
    }

    // ==================== create() 工厂方法测试 ====================

    public function testCreateReturnsCallable(): void
    {
        $callable = GuardListener::create(null);
        $this->assertIsCallable($callable);
    }

    public function testCreateWithoutPermissionService(): void
    {
        $callable = GuardListener::create(null);

        $result = $callable(['draft' => 1], []);
        $this->assertTrue($result);
    }

    public function testCreateWithPermissionService(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->with(42, 'workflow.order.submit')
            ->andReturn(true);

        $callable = GuardListener::create($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'submit',
        ];

        $result = $callable(['draft' => 1], $context);
        $this->assertTrue($result);
    }

    public function testCreateWithPermissionServiceDenied(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->with(42, 'workflow.order.delete')
            ->andReturn(false);

        $callable = GuardListener::create($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'delete',
        ];

        $result = $callable(['draft' => 1], $context);
        $this->assertFalse($result);
    }

    // ==================== 集成使用场景测试 ====================

    public function testGuardListenerUsedAsTransitionGuard(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();
        $permissionService
            ->shouldReceive('check')
            ->with(42, 'workflow.order.submit')
            ->andReturn(true);

        $guardCallable = GuardListener::create($permissionService);

        $context = [
            'workflow_name' => 'order',
            'transition_name' => 'submit',
        ];

        $result = $guardCallable(['draft' => 1], $context);
        $this->assertTrue($result);
    }

    public function testDifferentWorkflowPermissionKeys(): void
    {
        $this->setTestUserId(42);

        $permissionService = \Mockery::mock();

        $testCases = [
            ['order', 'submit', 'workflow.order.submit'],
            ['order', 'approve', 'workflow.order.approve'],
            ['document', 'publish', 'workflow.document.publish'],
            ['leave_request', 'manager_approve', 'workflow.leave_request.manager_approve'],
        ];

        foreach ($testCases as [$workflowName, $transitionName, $expectedKey]) {
            $permissionService
                ->shouldReceive('check')
                ->with(42, $expectedKey)
                ->andReturn(true);

            $listener = new GuardListener($permissionService);
            $result = $listener(['draft' => 1], [
                'workflow_name' => $workflowName,
                'transition_name' => $transitionName,
            ]);

            $this->assertTrue($result, "Failed for key: {$expectedKey}");
        }
    }
}
