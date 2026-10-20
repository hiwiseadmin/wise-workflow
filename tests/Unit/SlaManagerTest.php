<?php
declare(strict_types=1);

namespace wise\workflow\test\Unit;

use PHPUnit\Framework\TestCase;
use wise\workflow\model\Place;
use wise\workflow\model\Transition;
use wise\workflow\sla\SlaManager;
use wise\workflow\Workflow;
use wise\workflow\WorkflowRegistry;

/**
 * SLA 超时管理器单元测试
 *
 * 使用内存假模型（FakeMarkingModel）替代数据库，验证：
 * - checkWorkflow 超时检测与幂等保护
 * - notify / auto_reject / escalate 动作
 * - dry-run 模式不执行动作
 * - formatDuration 时长格式化
 * - checkAll 遍历所有工作流
 */
class SlaManagerTest extends TestCase
{
    private SlaManager $sla;
    private FakeMarkingModel $marking;
    private array $notified = [];

    protected function setUp(): void
    {
        // 安全审计阶段 2 / F-1：sla.enabled 现在是**生效**的开关。本类验证 SLA 检测与
        // 动作逻辑，因此显式声明「已启用」；关闭与 --force 的行为见下方专项用例。
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['sla' => ['enabled' => true]];

        WorkflowRegistry::clear();
        $this->marking = new FakeMarkingModel();
        $this->notified = [];
        $notifier = function (string $message, array $data): void {
            $this->notified[] = ['message' => $message, 'data' => $data];
        };
        $this->sla = new SlaManager($this->marking, null, $notifier, false, null);
    }

    protected function tearDown(): void
    {
        WorkflowRegistry::clear();
    }

    private function makeRow(string $subject, string $place, int $enteredAgoSec, ?int $lastSlaAgoSec = null, string $subjectType = 'order'): FakeMarkingRow
    {
        $row = new FakeMarkingRow();
        $row->subject = $subject;
        $row->subject_type = $subjectType;
        $row->marking = json_encode([$place => 1]);
        $row->entered_at = date('Y-m-d H:i:s', time() - $enteredAgoSec);
        $row->assigned_to = 10;
        $row->last_sla_at = $lastSlaAgoSec === null ? null : date('Y-m-d H:i:s', time() - $lastSlaAgoSec);
        return $row;
    }

    private function orderWorkflow(array $slaConfig): Workflow
    {
        $wf = new Workflow('order');
        $wf->addPlace(new Place('review'));
        $wf->addPlace(new Place('approved'));
        $wf->setMetadata(['sla_config' => $slaConfig]);
        return $wf;
    }

    // ==================== 无 SLA 配置 ====================

    public function testCheckWorkflowReturnsEmptyWhenNoSlaConfig(): void
    {
        $wf = new Workflow('order');
        $wf->addPlace(new Place('review'));
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 999999);

        $this->assertSame([], $this->sla->checkWorkflow($wf));
        $this->assertEmpty($this->notified);
    }

    // ==================== notify 动作 ====================

    public function testCheckWorkflowDetectsTimeoutAndNotifies(): void
    {
        $slaConfig = ['review' => ['timeout' => 86400, 'action' => 'notify']];
        $wf = $this->orderWorkflow($slaConfig);
        WorkflowRegistry::add($wf);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $results = $this->sla->checkWorkflow($wf);

        $this->assertCount(1, $results);
        $this->assertSame('order:1', $results[0]['subject']);
        $this->assertSame('review', $results[0]['place']);
        $this->assertSame('notify', $results[0]['action']);
        $this->assertCount(1, $this->notified);
        // 幂等保护：last_sla_at 应被更新
        $this->assertNotNull($this->marking->rows[0]->last_sla_at);
    }

    public function testCheckWorkflowSkipsNotTimedOut(): void
    {
        $slaConfig = ['review' => ['timeout' => 86400, 'action' => 'notify']];
        $wf = $this->orderWorkflow($slaConfig);
        // entered 仅 10 秒前，未超时
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 10);

        $this->assertSame([], $this->sla->checkWorkflow($wf));
        $this->assertEmpty($this->notified);
    }

    // ==================== sla.enabled 开关（安全审计阶段 2 / F-1）====================

    public function testSlaDisabledSkipsDetectionEvenWhenTimedOut(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['sla' => ['enabled' => false]];

        $wf = $this->orderWorkflow(['review' => ['timeout' => 60, 'action' => 'notify']]);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $this->assertSame([], $this->sla->checkWorkflow($wf), 'sla.enabled=false 时不得检测或执行动作');
        $this->assertEmpty($this->notified);
        $this->assertNull($this->marking->rows[0]->last_sla_at);
    }

    public function testForceOverridesDisabledSlaSwitch(): void
    {
        $GLOBALS['__WF_CONFIG_OVERRIDE'] = ['sla' => ['enabled' => false]];

        $wf = $this->orderWorkflow(['review' => ['timeout' => 60, 'action' => 'notify']]);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $this->sla->setForce(true);

        $this->assertCount(1, $this->sla->checkWorkflow($wf), 'setForce(true)（命令 --force）应能强制执行');
    }

    // ==================== dry-run 模式 ====================

    public function testDryRunDetectsButDoesNotAct(): void
    {
        $drySla = new SlaManager($this->marking, null, function (): void {
            $this->notified[] = true;
        }, true, null);

        $slaConfig = ['review' => ['timeout' => 86400, 'action' => 'notify']];
        $wf = $this->orderWorkflow($slaConfig);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $results = $drySla->checkWorkflow($wf);

        $this->assertCount(1, $results);
        $this->assertEmpty($this->notified, 'dry-run 不应触发通知');
        $this->assertNull($this->marking->rows[0]->last_sla_at, 'dry-run 不应更新 last_sla_at');
    }

    // ==================== auto_reject / escalate（无引擎回退通知）================

    public function testAutoRejectFallsBackToNotifierWhenNoEngine(): void
    {
        $slaConfig = ['review' => ['timeout' => 1, 'action' => 'auto_reject', 'reject_transition' => 'reject']];
        $wf = $this->orderWorkflow($slaConfig);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $results = $this->sla->checkWorkflow($wf);
        $this->assertCount(1, $results);
        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('待处理', $this->notified[0]['message']);
    }

    public function testEscalateFallsBackToNotifierWhenNoEngine(): void
    {
        $slaConfig = ['review' => ['timeout' => 1, 'action' => 'escalate', 'escalate_transition' => 'escalate', 'escalate_to' => 99]];
        $wf = $this->orderWorkflow($slaConfig);
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000);

        $results = $this->sla->checkWorkflow($wf);
        $this->assertCount(1, $results);
        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('待处理', $this->notified[0]['message']);
    }

    // ==================== 幂等保护 ====================

    public function testIdempotencySkipsRecentlyHandled(): void
    {
        $slaConfig = ['review' => ['timeout' => 86400, 'action' => 'notify']];
        $wf = $this->orderWorkflow($slaConfig);
        // 上次 SLA 处理时间距现在 100 秒（< timeout 86400），应跳过
        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000, 100);

        $this->assertSame([], $this->sla->checkWorkflow($wf));
        $this->assertEmpty($this->notified);
    }

    // ==================== formatDuration ====================

    public function testFormatDuration(): void
    {
        $ref = new \ReflectionMethod($this->sla, 'formatDuration');
        $ref->setAccessible(true);
        $this->assertSame('30秒', $ref->invoke($this->sla, 30));
        $this->assertSame('2分钟', $ref->invoke($this->sla, 120));
        $this->assertSame('2小时', $ref->invoke($this->sla, 7200));
        $this->assertSame('2天', $ref->invoke($this->sla, 172800));
    }

    public function testIsDryRunGetter(): void
    {
        $this->assertFalse($this->sla->isDryRun());
        $this->sla->setDryRun(true);
        $this->assertTrue($this->sla->isDryRun());
    }

    // ==================== checkAll ====================

    public function testCheckAllIteratesAllWorkflows(): void
    {
        $wf1 = new Workflow('order');
        $wf1->addPlace(new Place('review'));
        $wf1->setMetadata(['sla_config' => ['review' => ['timeout' => 1, 'action' => 'notify']]]);

        $wf2 = new Workflow('purchase');
        $wf2->addPlace(new Place('approved'));
        $wf2->setMetadata(['sla_config' => ['approved' => ['timeout' => 1, 'action' => 'notify']]]);

        WorkflowRegistry::add($wf1);
        WorkflowRegistry::add($wf2);

        $this->marking->rows[] = $this->makeRow('order:1', 'review', 100000, null, 'order');
        $this->marking->rows[] = $this->makeRow('purchase:1', 'approved', 100000, null, 'purchase');

        $results = $this->sla->checkAll();
        $this->assertCount(2, $results);
    }

    public function testCheckWorkflowIgnoresPlaceWithoutSla(): void
    {
        $slaConfig = ['review' => ['timeout' => 1, 'action' => 'notify']];
        $wf = $this->orderWorkflow($slaConfig);
        // marking 处于 approved（无 SLA 配置）
        $this->marking->rows[] = $this->makeRow('order:1', 'approved', 100000);

        $this->assertSame([], $this->sla->checkWorkflow($wf));
    }
}

/**
 * 内存版标记模型（仅实现 SlaManager 用到的查询接口）
 */
class FakeMarkingRow
{
    public $subject;
    public $subject_type;
    public $marking;
    public $entered_at;
    public $assigned_to;
    public $last_sla_at;
}

class FakeMarkingModel
{
    /** @var FakeMarkingRow[] */
    public array $rows = [];
    private array $conds = [];

    public function where($field, $op = null, $value = null): self
    {
        $this->conds[] = [$field, $value ?? $op];
        return $this;
    }

    public function select(): array
    {
        $conds = $this->conds;
        $this->conds = [];
        return array_values(array_filter($this->rows, fn($row) => $this->match($row, $conds)));
    }

    public function update(array $data): int
    {
        $conds = $this->conds;
        $this->conds = [];
        $n = 0;
        foreach ($this->rows as $row) {
            if ($this->match($row, $conds)) {
                foreach ($data as $k => $v) {
                    $row->$k = $v;
                }
                $n++;
            }
        }
        return $n;
    }

    private function match($row, array $conds): bool
    {
        foreach ($conds as [$f, $v]) {
            if (($row->$f ?? null) != $v) {
                return false;
            }
        }
        return true;
    }
}
