<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\exception\WorkflowException;
use wise\workflow\model\WorkflowTask;
use wise\workflow\task\TaskManager;

/**
 * 并行审批任务管理器单元测试
 *
 * 使用内存版 WorkflowTask 子类（InMemoryWorkflowTask）替代数据库，验证：
 * - createTasks 创建 / 重复校验 / 空审批人校验
 * - completeTask 各审批策略（all/any/ratio/count）触发流转判定
 * - 驳回立即流转
 * - 并发冲突 / 重复完成 / 任务不存在等异常
 * - 任务查询 / 取消 / 转交
 */
class TaskManagerTest extends TestCase
{
    private InMemoryWorkflowTask $taskModel;
    private TaskManager $manager;
    private FakeEventService $eventService;

    protected function setUp(): void
    {
        $this->taskModel = new InMemoryWorkflowTask();
        $this->manager = new TaskManager($this->taskModel, null);
        $this->eventService = new FakeEventService();
        $this->manager->setEventService($this->eventService);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__WF_CONFIG_OVERRIDE']);
    }

    private function createApproveTasks(int $count, string $policy = 'all', $policyValue = null): void
    {
        $assignees = [];
        for ($i = 1; $i <= $count; $i++) {
            $assignees[] = 100 + $i;
        }
        $this->manager->createTasks(
            $this->workflow(),
            'order:1',
            'approve',
            $assignees,
            $policy,
            $policyValue,
            []
        );
    }

    private function workflow(): \wise\workflow\Workflow
    {
        return new \wise\workflow\Workflow('order');
    }


    public function testCreateTasksStoresOnePerAssignee(): void
    {
        $tasks = $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [101, 102, 103], 'all');
        $this->assertCount(3, $tasks);
        $this->assertSame('approve_1', $tasks[0]['task_key']);
        $this->assertSame(101, $tasks[0]['assignee']);
        $this->assertSame(WorkflowTask::STATUS_PENDING, $tasks[0]['status']);
    }

    public function testCreateTasksThrowsWhenPendingExists(): void
    {
        $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [101], 'all');
        $this->expectException(WorkflowException::class);
        $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [102], 'all');
    }

    public function testCreateTasksThrowsWhenAssigneesEmpty(): void
    {
        $this->expectException(WorkflowException::class);
        $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [], 'all');
    }


    public function testCompleteTaskAllPolicyTriggersWhenAllDone(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertFalse($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved', ['comment' => 'ok']));
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_2', 102, 'approved', ['comment' => 'ok']));
        $this->assertCount(1, $this->eventService->triggered);
    }


    public function testCompleteTaskAnyPolicyTriggersOnFirst(): void
    {
        $this->createApproveTasks(3, 'any');
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved'));
        $this->assertCount(1, $this->eventService->triggered);
    }


    public function testCompleteTaskCountPolicy(): void
    {
        $this->createApproveTasks(3, 'count', 2);
        $this->assertFalse($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved'));
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_2', 102, 'approved'));
    }


    public function testCompleteTaskRatioPolicy(): void
    {
        $this->createApproveTasks(2, 'ratio', 0.6);
        $this->assertFalse($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved'));
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_2', 102, 'approved'));
    }


    public function testCompleteTaskRejectedTriggersImmediately(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'rejected', ['comment' => 'no']));
    }


    public function testCompleteTaskThrowsWhenTaskNotFound(): void
    {
        $this->expectException(WorkflowException::class);
        $this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
    }

    public function testCompleteTaskThrowsWhenNotPending(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
        $this->expectException(WorkflowException::class);
        $this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
    }

    /**
     * 安全审计阶段 1 / C-2：完成人必须是任务分配人。
     * 原用例 testCompleteTaskUpdatesActualAssigneeWhenDifferent 断言「非分配人 999 可完成
     * assignee=101 的任务并把 assignee 改写成 999」，等于把越权行为固化为预期——此处反转。
     */
    public function testCompleteTaskThrowsWhenActorIsNotAssignee(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('仅任务分配人可完成');
        $this->manager->completeTask('order:1', 'approve', 'approve_1', 999, 'approved');
    }

    public function testCompleteTaskKeepsOriginalAssigneeWhenActorMatches(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved'));
        $rows = $this->taskModel->rows;
        $this->assertSame(101, $rows[0]['assignee'], 'assignee 不应被改写为完成人');
    }


    public function testGetPendingTasks(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertCount(2, $this->manager->getPendingTasks('order:1'));
    }

    public function testGetUserTasksFiltersByAssignee(): void
    {
        $this->createApproveTasks(2, 'all');
        $tasks = $this->manager->getUserTasks(101);
        $this->assertCount(1, $tasks);
        $this->assertSame(101, $tasks[0]->assignee);
    }

    public function testGetPendingTasksForTransition(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertCount(2, $this->manager->getPendingTasksForTransition('order:1', 'approve'));
    }


    public function testCancelTasksReturnsTrueWhenPendingExist(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertTrue($this->manager->cancelTasks('order:1'));
        $this->assertCount(0, $this->manager->getPendingTasks('order:1'));
    }

    public function testCancelTasksReturnsFalseWhenNone(): void
    {
        $this->assertFalse($this->manager->cancelTasks('order:1'));
    }

    public function testCancelTasksForTransition(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertTrue($this->manager->cancelTasks('order:1', 'approve'));
    }


    public function testDelegateTaskCreatesNewPending(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->assertTrue($this->manager->delegateTask('order:1', 'approve', 'approve_1', 101, 202));
        $pending = $this->manager->getPendingTasks('order:1');
        $this->assertCount(1, $pending);
        $this->assertSame(202, $pending[0]->assignee);
    }

    public function testDelegateTaskReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->manager->delegateTask('order:1', 'approve', 'approve_1', 101, 202));
    }

    /**
     * 安全审计阶段 1 / C-3：仅任务分配人可转交（此前 fromUserId 从未被校验）
     */
    public function testDelegateTaskThrowsWhenFromUserIsNotAssignee(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('仅任务分配人可转交');
        $this->manager->delegateTask('order:1', 'approve', 'approve_1', 777, 202);
    }

    /**
     * 回归：cancelTasks 后重开会签，新一轮任务必须可与旧轮次区分
     *
     * 此前 task_key 恒为 "{transition}_{index}"，重开轮次会生成完全相同的 key，
     * completeTask 的 find() 命中旧行（cancelled）→ 抛「不可重复完成」，会签永久卡死。
     */
    public function testReopenedRoundIsCompletable(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertTrue($this->manager->cancelTasks('order:1', 'approve'));

        $tasks = $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [101, 102], 'all');
        $this->assertNotSame('approve_1', $tasks[0]['task_key'], '重开轮次不得复用旧 key');

        $this->assertFalse($this->manager->completeTask('order:1', 'approve', $tasks[0]['task_key'], 101, 'approved'));
        $this->assertTrue($this->manager->completeTask('order:1', 'approve', $tasks[1]['task_key'], 102, 'approved'));
        $this->assertCount(1, $this->eventService->triggered);
    }

    /**
     * 回归：转交不虚增会签分母
     *
     * 转交会留下一条 delegated 行并新增一条 pending 行；若按原始行数统计分母，
     * 2 人需 3 票才能流转，policy=all 永远达不成。
     */
    public function testDelegationDoesNotInflateRequiredApprovals(): void
    {
        $this->createApproveTasks(2, 'all');
        $this->assertTrue($this->manager->delegateTask('order:1', 'approve', 'approve_1', 101, 201));

        $this->assertFalse($this->manager->completeTask('order:1', 'approve', 'approve_2', 102, 'approved'));

        $pending = $this->manager->getPendingTasks('order:1');
        $this->assertCount(1, $pending);
        $this->assertTrue($this->manager->completeTask(
            'order:1',
            'approve',
            $pending[0]->task_key,
            201,
            'approved'
        ));
    }


    public function testCompleteTaskThrowsWhenNoEngineAndNoEventService(): void
    {
        $manager = new TaskManager($this->taskModel, null);
        $manager->createTasks($this->workflow(), 'order:1', 'approve', [101], 'all');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('无法执行状态转换');
        $manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
    }

    /**
     * 安全审计阶段 2 / F-2：事件驱动路径失败时必须把任务恢复为 pending。
     * 此前只有 V1 直接调用分支有补偿，事件分支失败会让任务停在 completed
     * 而状态未流转，且 pending 守卫使重试不可能（任务被「吃掉」）。
     */
    public function testCompleteTaskRestoresPendingWhenEventPathFails(): void
    {
        $manager = new TaskManager($this->taskModel, null);
        $manager->createTasks($this->workflow(), 'order:1', 'approve', [101], 'all');

        $manager->setEventService(new class {
            public function trigger(string $name, array $payload): void
            {
                throw new \RuntimeException('事件处理器失败');
            }
        });

        try {
            $manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
            $this->fail('事件失败应当向上抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('事件处理器失败', $e->getMessage());
        }

        $rows = $this->taskModel->rows;
        $this->assertSame('pending', $rows[0]['status'], '事件路径失败后任务必须恢复为 pending');
    }


    public function testCreateTasksRejectsTooManyAssignees(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['task' => ['max_assignees' => 3]];

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('超出允许范围');
        $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [1, 2, 3, 4]);
    }

    public function testCreateTasksDeduplicatesAssignees(): void
    {
        $tasks = $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [101, 101, '101', 102]);

        $this->assertCount(2, $tasks, '重复的 assignee 必须先去重再入库');
    }

    public function testCreateTasksRejectsUnknownPolicy(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('不支持的审批策略');
        $this->manager->createTasks($this->workflow(), 'order:1', 'approve', [101], 'bogus');
    }


    /**
     * createTasks / completeTask 的写入必须落在事务里：中途失败不能留下半个审批组，
     * 也不能出现「任务已 completed、状态却没流转」的中间态。
     */
    public function testWritesAreWrappedInTransaction(): void
    {
        $this->createApproveTasks(1, 'all');
        $this->assertSame(1, $this->taskModel->transBegun, 'createTasks 必须开启事务');
        $this->assertSame(1, $this->taskModel->transCommitted, 'createTasks 成功后必须提交');

        $this->manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
        $this->assertSame(2, $this->taskModel->transBegun, 'completeTask 必须开启事务');
        $this->assertSame(2, $this->taskModel->transCommitted);
        $this->assertSame(0, $this->taskModel->transRolledBack);
    }

    public function testCompleteTaskRollsBackWhenEventFails(): void
    {
        $manager = new TaskManager($this->taskModel, null);
        $manager->createTasks($this->workflow(), 'order:1', 'approve', [101], 'all');

        $manager->setEventService(new class {
            public function trigger(string $name, array $payload): void
            {
                throw new \RuntimeException('事件处理器失败');
            }
        });

        try {
            $manager->completeTask('order:1', 'approve', 'approve_1', 101, 'approved');
            $this->fail('事件失败应当向上抛出');
        } catch (\RuntimeException $e) {
            $this->assertSame('事件处理器失败', $e->getMessage());
        }

        $this->assertSame(1, $this->taskModel->transRolledBack, '事件失败必须回滚事务');
        $this->assertSame(0, $this->taskModel->transCommitted - 1, 'createTasks 提交过 1 次，completeTask 不应提交');
    }
}

/**
 * 内存版 WorkflowTask（覆盖查询方法，不连接数据库）
 */
class InMemoryWorkflowTask extends WorkflowTask
{
    /** @var array<int, array> */
    public array $rows = [];
    private int $autoId = 0;
    private array $conds = [];

    /** 事务计数（TaskManager 用 startTrans/commit/rollback 包裹写入） */
    public int $transBegun      = 0;
    public int $transCommitted  = 0;
    public int $transRolledBack = 0;

    public function startTrans(): void
    {
        $this->transBegun++;
    }

    public function commit(): void
    {
        $this->transCommitted++;
    }

    public function rollback(): void
    {
        $this->transRolledBack++;
    }

    public function where($field, $op = null, $value = null): self
    {
        $this->conds[] = [$field, $value ?? $op];
        return $this;
    }

    public function find()
    {
        $conds = $this->conds;
        $this->conds = [];
        foreach ($this->rows as $i => $row) {
            if ($this->match($row, $conds)) {
                return new InMemoryTask($this, $row, $row['id']);
            }
        }
        return null;
    }

    public function select(): TaskCollection
    {
        $conds = $this->conds;
        $this->conds = [];
        $arr = [];
        foreach ($this->rows as $i => $row) {
            if ($this->match($row, $conds)) {
                $arr[] = new InMemoryTask($this, $row, $row['id']);
            }
        }
        return new TaskCollection($arr);
    }

    public function insert($data)
    {
        $id = ++$this->autoId;
        $this->rows[] = array_merge(['id' => $id], $data);
        return true;
    }

    public function update($data)
    {
        $conds = $this->conds;
        $this->conds = [];
        $n = 0;
        foreach ($this->rows as &$row) {
            if ($this->match($row, $conds)) {
                $row = array_merge($row, $data);
                $n++;
            }
        }
        return $n;
    }

    public function count(): int
    {
        $conds = $this->conds;
        $this->conds = [];
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->match($row, $conds)) {
                $n++;
            }
        }
        return $n;
    }

    public function persist(InMemoryTask $task): void
    {
        foreach ($this->rows as &$row) {
            if (($row['id'] ?? null) === $task->id) {
                $row = array_merge($row, $task->toRow());
            }
        }
    }

    private function match(array $row, array $conds): bool
    {
        foreach ($conds as [$f, $v]) {
            if (($row[$f] ?? null) != $v) {
                return false;
            }
        }
        return true;
    }
}

class InMemoryTask
{
    public $id;
    public $subject;
    public $transition_name;
    public $task_key;
    public $assignee;
    public $status;
    public $policy;
    public $policy_value;
    public $result;
    public $comment;
    public $completed_at;
    public $create_time;
    public $update_time;
    private $store;

    public function __construct(InMemoryWorkflowTask $store, array $row, $id)
    {
        $this->store = $store;
        foreach ($row as $k => $v) {
            $this->$k = $v;
        }
    }

    public function save(): void
    {
        $this->store->persist($this);
    }

    public function toRow(): array
    {
        return [
            'id'               => $this->id,
            'subject'          => $this->subject,
            'transition_name'  => $this->transition_name,
            'task_key'         => $this->task_key,
            'assignee'         => $this->assignee,
            'status'           => $this->status,
            'policy'           => $this->policy,
            'policy_value'     => $this->policy_value,
            'result'           => $this->result,
            'comment'          => $this->comment,
            'completed_at'     => $this->completed_at,
            'create_time'      => $this->create_time,
            'update_time'      => $this->update_time,
        ];
    }
}

class FakeEventService
{
    public array $triggered = [];

    public function trigger(string $name, array $payload): void
    {
        $this->triggered[] = ['name' => $name, 'payload' => $payload];
    }
}

class TaskCollection implements \ArrayAccess, \IteratorAggregate, \Countable
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
