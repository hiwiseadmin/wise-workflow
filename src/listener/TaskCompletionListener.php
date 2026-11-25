<?php
declare(strict_types=1);

namespace wise\workflow\listener;

use wise\workflow\exception\WorkflowException;
use wise\workflow\WorkflowEngine;
use wise\workflow\WorkflowRegistry;
use wise\workflow\event\TaskCompletedEvent;

/**
 * 任务完成监听器（Task Completion Listener）
 *
 * V2: 监听 workflow.task_completed 事件，
 * 将并行审批完成后的状态转换委托给 WorkflowEngine::apply()。
 *
 * 替代了原来 TaskManager → Engine::apply() 的直接调用，
 * 消除了循环依赖。
 *
 * 注册方式：
 * <pre>
 * $engine = new WorkflowEngine($markingStore, $eventService, ...);
 * $listener = new TaskCompletionListener($engine);
 * $eventService->listen('workflow.task_completed', [$listener, 'handle']);
 * </pre>
 *
 * @package wise\workflow
 */
class TaskCompletionListener
{
    /**
     * 工作流引擎
     * @var WorkflowEngine
     */
    protected $engine;

    /**
     * 构造函数
     */
    public function __construct(WorkflowEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * 事件处理入口
     *
     * @param array $payload 事件载荷，第一个元素为 TaskCompletedEvent
     * @return void
     */
    public function handle(array $payload): void
    {
        $event = $payload[0] ?? null;

        if (!$event instanceof TaskCompletedEvent) {
            return;
        }

        $parts = explode(':', $event->subject, 2);
        $workflowName = $parts[0] ?? '';

        if (empty($workflowName) || !WorkflowRegistry::has($workflowName)) {
            throw new WorkflowException("工作流 [{$workflowName}] 未注册");
        }

        $workflow = WorkflowRegistry::get($workflowName);
        $context = $event->context;
        $context['remark'] = $context['comment'] ?? '审批完成';

        $this->engine->apply(
            $workflow,
            $event->subject,
            $event->transitionName,
            $context
        );
    }
}
