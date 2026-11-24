<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\event\TaskCompletedEvent;
use wise\workflow\exception\WorkflowException;
use wise\workflow\listener\TaskCompletionListener;
use wise\workflow\Workflow;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;

/**
 * 任务完成监听器单元测试
 *
 * 通过 PHPUnit 原生 mock 注入 WorkflowEngine，验证：
 * - 非 TaskCompletedEvent 负载直接返回
 * - 工作流未注册抛出异常
 * - 已注册时调用 engine->apply 并传递 subject / transitionName / context
 */
class TaskCompletionListenerTest extends TestCase
{
    private $engine;
    private TaskCompletionListener $listener;

    protected function setUp(): void
    {
        WorkflowRegistry::clear();
        $this->engine = $this->createMock(WorkflowEngine::class);
        $this->listener = new TaskCompletionListener($this->engine);
    }

    protected function tearDown(): void
    {
        WorkflowRegistry::clear();
    }

    private function event(string $subject = 'order:1', string $transition = 'approve'): TaskCompletedEvent
    {
        return new TaskCompletedEvent($subject, $transition, 'all', ['comment' => 'ok']);
    }

    public function testNonTaskCompletedEventReturnsEarly(): void
    {
        $this->listener->handle(['foo' => 'bar']);
        $this->assertTrue(true);
    }

    public function testWrongEventTypeReturnsEarly(): void
    {
        $this->listener->handle([new \stdClass()]);
        $this->assertTrue(true);
    }

    public function testUnregisteredWorkflowThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->listener->handle([$this->event()]);
    }

    public function testRegisteredWorkflowTriggersApply(): void
    {
        $wf = new Workflow('order');
        WorkflowRegistry::add($wf);

        $this->engine->expects($this->once())
            ->method('apply')
            ->with(
                $this->callback(fn($w) => $w instanceof Workflow && $w->getName() === 'order'),
                'order:1',
                'approve',
                $this->callback(fn($c) => isset($c['remark']) && $c['remark'] === 'ok')
            );

        $this->listener->handle([$this->event()]);
    }

    public function testSubjectWithoutColonUsesFullName(): void
    {
        $wf = new Workflow('order_123');
        WorkflowRegistry::add($wf);

        $this->engine->expects($this->once())
            ->method('apply')
            ->with(
                $this->callback(fn($w) => $w instanceof Workflow && $w->getName() === 'order_123'),
                'order_123',
                'approve',
                $this->isType('array')
            );

        $this->listener->handle([$this->event('order_123', 'approve')]);
    }
}
