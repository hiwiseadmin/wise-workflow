<?php
declare(strict_types=1);

namespace wise\workflow;

use wise\workflow\model\WorkflowMarking;
use wise\workflow\model\WorkflowHistory;

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

    public function __construct($markingModel = null, $historyModel = null, ?callable $notifier = null)
    {
        $this->markingModel = $markingModel ?: new WorkflowMarking();
        $this->historyModel = $historyModel ?: new WorkflowHistory();
        $this->notifier     = $notifier;
    }

    /**
     * 检查所有超时实例
     *
     * @return array 超时实例列表
     */
    public function checkAll(): array
    {
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
        $slaConfig    = $workflow->getMeta('sla_config', []);
        $results      = [];

        if (empty($slaConfig)) {
            return $results;
        }

        // 获取所有该工作流的活跃实例
        $prefix = $workflow->getName() . ':';
        $markings = $this->markingModel
            ->where('subject', 'like', $prefix . '%')
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
                $elapsed    = time() - strtotime($enteredAt);

                if ($elapsed >= $timeout) {
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
            $duration = $this->formatDuration($result['elapsed']);
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
     * 自动拒绝（需要具体实现注入）
     */
    protected function autoReject(array $result, Workflow $workflow, array $sla): void
    {
        // 基类留空，由业务层在 notifier 或监听事件中实现
        // 例如：Engine::apply($workflow, $result['subject'], 'reject', ['auto' => true]);
    }

    /**
     * 升级处理（需要具体实现注入）
     */
    protected function escalate(array $result, Workflow $workflow, array $sla): void
    {
        // 基类留空，由业务层在 notifier 或监听事件中实现
        $escalateTo = $sla['escalate_to'] ?? null;
        if ($escalateTo && $this->notifier) {
            ($this->notifier)('escalate', array_merge($result, ['escalate_to' => $escalateTo]));
        }
    }
}
