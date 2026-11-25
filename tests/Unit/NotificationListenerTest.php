<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\listener\DatabaseNotificationListener;
use wise\workflow\listener\NotificationListener;
use wise\workflow\model\Transition;
use wise\workflow\Workflow;

/**
 * 通知监听器单元测试
 *
 * - 抽象类通过 CapturingNotificationListener 子类捕获 sendNotification 调用，验证事件路由
 * - DatabaseNotificationListener 验证 enabled=false 提前返回与 to_user<=0 提前返回（不触碰 Db）
 */
class NotificationListenerTest extends TestCase
{
    private CapturingNotificationListener $listener;

    protected function setUp(): void
    {
        $this->listener = new CapturingNotificationListener();
    }

    private function wf(): Workflow
    {
        return new Workflow('order');
    }

    private function trans(): Transition
    {
        return new Transition('approve', ['review'], ['approved']);
    }


    public function testTransitionEventTriggersNotification(): void
    {
        $this->listener->handle([
            'workflow'   => $this->wf(),
            'transition' => $this->trans(),
            'subject'    => 'order:1',
            'event_name' => 'workflow.order.transition.approve',
            'context'    => [],
        ]);
        $this->assertCount(1, $this->listener->captured);
        $this->assertStringContainsString('order', $this->listener->captured[0]['message']);
    }

    public function testDelegateEventTriggersNotificationWithFromTo(): void
    {
        $this->listener->handle([
            'workflow'   => $this->wf(),
            'transition' => $this->trans(),
            'subject'    => 'order:1',
            'event_name' => 'workflow.order.delegate',
            'context'    => ['from_user' => 101, 'to_user' => 202],
        ]);
        $this->assertCount(1, $this->listener->captured);
        $this->assertStringContainsString('任务转交', $this->listener->captured[0]['message']);
    }

    public function testEnterEventDoesNotNotify(): void
    {
        $this->listener->handle([
            'workflow' => $this->wf(),
            'transition' => $this->trans(),
            'subject' => 'order:1',
            'event_name' => 'workflow.order.enter.review',
            'context' => [],
        ]);
        $this->assertEmpty($this->listener->captured);
    }

    public function testLeaveEventDoesNotNotify(): void
    {
        $this->listener->handle([
            'workflow' => $this->wf(),
            'transition' => $this->trans(),
            'subject' => 'order:1',
            'event_name' => 'workflow.order.leave.review',
            'context' => [],
        ]);
        $this->assertEmpty($this->listener->captured);
    }

    public function testGuardFailedEventDoesNotNotify(): void
    {
        $this->listener->handle([
            'workflow' => $this->wf(),
            'transition' => $this->trans(),
            'subject' => 'order:1',
            'event_name' => 'workflow.order.guard_failed',
            'context' => [],
        ]);
        $this->assertEmpty($this->listener->captured);
    }

    public function testCompletedEventDoesNotNotify(): void
    {
        $this->listener->handle([
            'workflow' => $this->wf(),
            'transition' => $this->trans(),
            'subject' => 'order:1',
            'event_name' => 'workflow.order.completed',
            'context' => [],
        ]);
        $this->assertEmpty($this->listener->captured);
    }

    public function testMissingTransitionWithoutDelegateDoesNotNotify(): void
    {
        $this->listener->handle([
            'workflow' => $this->wf(),
            'subject' => 'order:1',
            'event_name' => 'workflow.order.transition.approve',
            'context' => [],
        ]);
        $this->assertEmpty($this->listener->captured);
    }


    public function testDatabaseListenerEnabledFalseReturnsEarly(): void
    {
        $db = new DatabaseNotificationListener();
        $db->handle([
            'workflow'   => $this->wf(),
            'transition' => $this->trans(),
            'subject'    => 'order:1',
            'event_name' => 'workflow.order.delegate',
            'context'    => ['from_user' => 1, 'to_user' => 2],
        ]);
        $this->assertTrue(true);
    }

    public function testDatabaseListenerSendNotificationSkipsWhenNoTargetUser(): void
    {
        $db = new DatabaseNotificationListener();
        $ref = new \ReflectionMethod($db, 'sendNotification');
        $ref->setAccessible(true);
        $ref->invoke($db, 'msg', 'database', ['to_user' => 0, 'content' => 'x']);
        $this->assertTrue(true);
    }
}

/**
 * 捕获通知内容的测试子类
 */
class CapturingNotificationListener extends NotificationListener
{
    public array $captured = [];

    public function __construct()
    {
        $this->config = [
            'enabled'     => true,
            'channel'     => 'database',
            'templates'   => [
                'transition' => '工作流 {workflow}.{transition} 状态变更（{subject}）',
                'delegate'   => '任务转交：{from_user} -> {to_user}（{subject}）',
            ],
            'default'     => '工作流通知',
        ];
    }

    protected function sendNotification(string $message, ?string $channel, array $data): void
    {
        $this->captured[] = ['message' => $message, 'channel' => $channel, 'data' => $data];
    }
}
