<?php
declare(strict_types=1);

namespace wise\workflow\sla;

use wise\workflow\model\WorkflowMarking;
use wise\workflow\model\WorkflowHistory;
use wise\workflow\Workflow;
use wise\workflow\WorkflowRegistry;

/**
 * SLA 超时管理器（SLA Manager）
 *
 * 检测工作流实例是否在指定状态超时
 * 支持三种超时动作：
 * - notify:    发送通知
 * - auto_reject: 自动拒绝
 * - escalate:  升级到指定状态/处理人
 *
 * @package wise\workflow
 */
class SlaManager
{
    /**
     * 标记模型
     * @var WorkflowMarking
     */
    protected $markingModel;

    /**
     * 历史模型
     * @var WorkflowHistory
     */
    protected $historyModel;

    /**
     * 通知回调
     * @var callable|null
     */
    protected $notifier;

    /**
     * 是否处于 dry-run 模式（仅检测不执行动作）
     * @var bool
     */
    protected $dryRun = false;

    /**
     * 是否忽略 sla.enabled 开关强制执行（安全审计阶段 2 / F-1）
     * @var bool
     */
    protected bool $force = false;

    /**
     * 工作流引擎（可选，用于 SLA 自动执行状态转换）
     * @var mixed
     */
    protected $engine = null;

    public function __construct($markingModel = null, $historyModel = null, ?callable $notifier = null, bool $dryRun = false, $engine = null)
    {
        $this->markingModel = $markingModel ?: new WorkflowMarking();
        $this->historyModel = $historyModel ?: new WorkflowHistory();
        $this->notifier     = $notifier;
        $this->dryRun       = $dryRun;
        $this->engine       = $engine;
    }

    /**
     * 设置 dry-run 模式
     */
    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    /**
     * 设置强制执行（忽略 sla.enabled 开关，安全审计阶段 2 / F-1）
     */
    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    /**
     * 是否处于 dry-run 模式
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * SLA 是否启用（安全审计阶段 2 / F-1）
     *
     * `wise-workflow.sla.enabled` 此前是**死开关**：配置写着关闭，命令仍会扫描
     * 并自动 reject/escalate。现在真正生效；需要执行时必须把开关设为 true，
     * 或显式 setForce(true)（命令行 `--force`）。
     */
    protected function isEnabled(): bool
    {
        if ($this->force) {
            return true;
        }

        try {
            return (bool) config('wise-workflow.sla.enabled', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查所有超时实例
     *
     * @return array 超时实例列表
     */
    public function checkAll(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $workflows = WorkflowRegistry::all();

        foreach ($workflows as $workflow) {
            $workflowResults = $this->checkWorkflow($workflow);
            $results = array_merge($results, $workflowResults);
        }

        return $results;
    }

    /**
     * 检查单个工作流的超时实例
     *
     * @param Workflow $workflow
     * @return array
     */
    public function checkWorkflow(Workflow $workflow): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $slaConfig    = $workflow->getMeta('sla_config', []);
        $results      = [];

        if (empty($slaConfig)) {
            return $results;
        }

        $markings = $this->markingModel
            ->where('subject_type', $workflow->getName())
            ->select();

        foreach ($markings as $marking) {
            $currentMarking = json_decode($marking->marking, true) ?: [];
            foreach ($currentMarking as $placeName => $val) {
                if (!isset($slaConfig[$placeName])) {
                    continue;
                }

                $sla = $slaConfig[$placeName];
                if (!is_array($sla)) {
                    continue;
                }

                $timeout    = (int)($sla['timeout'] ?? 86400);
                $action     = $sla['action'] ?? 'notify';
                $enteredAt  = $marking->entered_at;

                if (empty($enteredAt)) {
                    continue;
                }

                $enteredTs = strtotime($enteredAt);
                if ($enteredTs === false || $enteredTs <= 0) {
                    continue;
                }

                $elapsed    = time() - $enteredTs;

                if ($elapsed >= $timeout) {
                    $lastSlaAt = $marking->last_sla_at ?? null;
                    if ($lastSlaAt && (time() - strtotime($lastSlaAt)) < $timeout) {
                        continue;
                    }

                    $result = [
                        'subject'     => $marking->subject,
                        'workflow'    => $workflow->getName(),
                        'place'       => $placeName,
                        'timeout'     => $timeout,
                        'elapsed'     => $elapsed,
                        'action'      => $action,
                        'entered_at'  => $enteredAt,
                        'assigned_to' => $marking->assigned_to,
                    ];

                    $this->executeAction($action, $result, $workflow, $sla);
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * 执行超时动作
     */
    protected function executeAction(string $action, array $result, Workflow $workflow, array $sla): void
    {
        if ($this->dryRun) {
            return;
        }

        switch ($action) {
            case 'notify':
                $this->sendNotification($result, $workflow, $sla);
                break;
            case 'auto_reject':
                $this->autoReject($result, $workflow, $sla);
                break;
            case 'escalate':
                $this->escalate($result, $workflow, $sla);
                break;
        }

        $this->markingModel->where('subject', $result['subject'])
            ->update(['last_sla_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 发送超时通知
     */
    protected function sendNotification(array $result, Workflow $workflow, array $sla): void
    {
        if ($this->notifier) {
            $template = config('wise-workflow.notification.templates.sla_alert',
                '工作流 [{workflow}] 在 [{place}] 状态已超时 [{duration}]'
            );
            $duration = $this->formatDuration($result['elapsed'] ?? 0);
            $message  = str_replace(
                ['{workflow}', '{place}', '{duration}'],
                [$result['workflow'], $result['place'], $duration],
                $template
            );
            ($this->notifier)($message, $result);
        }
    }

    /**
     * 格式化时长
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}秒";
        if ($seconds < 3600) return floor($seconds / 60) . '分钟';
        if ($seconds < 86400) return floor($seconds / 3600) . '小时';
        return floor($seconds / 86400) . '天';
    }

    /**
     * 自动拒绝：超时后执行 reject 转换驱动状态流转
     */
    protected function autoReject(array $result, Workflow $workflow, array $sla): void
    {
        $transitionName = $sla['reject_transition'] ?? 'reject';
        $this->applySlaTransition($workflow, $result, $transitionName, [], 'auto_reject');
    }

    /**
     * 升级处理：超时后执行 escalate 转换（可同时指派处理人）
     */
    protected function escalate(array $result, Workflow $workflow, array $sla): void
    {
        $transitionName = $sla['escalate_transition'] ?? 'escalate';
        $context = [];
        if (!empty($sla['escalate_to'])) {
            $context['assigned_to'] = $sla['escalate_to'];
        }
        $this->applySlaTransition($workflow, $result, $transitionName, $context, 'escalate');
    }

    /**
     * 执行 SLA 触发的状态转换（自动拒绝 / 升级）
     *
     * 若注入了工作流引擎则真正执行状态转换；否则退化为 notifier 通知，
     * 以保持与历史上"仅通知"行为的兼容性。
     *
     * @param Workflow $workflow       工作流定义
     * @param array    $result         SLA 超时结果
     * @param string   $transitionName 目标转换名称
     * @param array       $context       转换上下文（普通业务数据）
     * @param string|null $systemTrigger 系统触发原因（'auto_reject' / 'escalate'），
     *                                   由引擎写入受控标记以跳过用户权限校验（不再放进 context）
     */
    protected function applySlaTransition(
        Workflow $workflow,
        array $result,
        string $transitionName,
        array $context,
        ?string $systemTrigger = null
    ): void {
        if ($this->dryRun) {
            return;
        }

        if ($this->engine !== null) {
            try {
                $this->engine->apply($workflow, $result['subject'], $transitionName, $context, $systemTrigger);
                return;
            } catch (\Throwable $e) {
                if ($this->notifier) {
                    ($this->notifier)("SLA 动作 [{$transitionName}] 执行失败: " . $e->getMessage(), $result);
                }
                return;
            }
        }

        if ($this->notifier) {
            ($this->notifier)("SLA 动作 [{$transitionName}] 待处理", array_merge($result, $context));
        }
    }
}
