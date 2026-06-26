<?php
declare(strict_types=1);

namespace wise\workflow;

use think\facade\Session;

/**
 * 数据库状态存储实现（Database Marking Store）
 * 
 * 实现 MarkingStoreInterface 接口
 * 使用数据库持久化工作流的状态（标记）和历史记录
 * 
 * 支持：
 * - 乐观锁并发控制
 * - 结构化 Subject（type + id）
 * - 处理人分配
 * - 操作类型分类
 * 
 * @package wise\workflow
 */
class DatabaseMarkingStore implements MarkingStoreInterface
{
    /**
     * 状态存储模型对象
     * @var \think\Model
     */
    protected $markingModel;

    /**
     * 历史记录模型对象
     * @var \think\Model
     */
    protected $historyModel;

    /**
     * 构造函数
     */
    public function __construct($markingModel = null, $historyModel = null)
    {
        $this->markingModel = $markingModel ?: new model\WorkflowMarking();
        $this->historyModel = $historyModel ?: new model\WorkflowHistory();
    }

    /**
     * {@inheritdoc}
     */
    public function getMarking(string $subject): array
    {
        $record = $this->markingModel->where('subject', $subject)->find();
        if (!$record) {
            return [];
        }
        return json_decode($record->marking, true) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function setMarking(string $subject, array $marking, array $context = [], int $expectedVersion = 0): int
    {
        $record = $this->markingModel->where('subject', $subject)->find();
        $now    = date('Y-m-d H:i:s');

        $data = [
            'marking'    => json_encode($marking, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ];

        // 解析结构化 subject
        $subjectType = $context['subject_type'] ?? null;
        if ($subjectType === null && strpos($subject, ':') !== false) {
            [$subjectType] = explode(':', $subject, 2);
        }
        if ($subjectType) {
            $data['subject_type'] = $subjectType;
        }

        // defense_version
        if (isset($context['definition_version'])) {
            $data['definition_version'] = $context['definition_version'];
        }

        // entered_at 切换状态时更新
        $data['entered_at'] = $now;

        if ($record) {
            $currentVersion = (int)$record->version;

            // 乐观锁检查
            if ($expectedVersion > 0 && $currentVersion !== $expectedVersion) {
                throw new exception\WorkflowException(
                    "乐观锁冲突：subject [{$subject}] 版本不匹配 (期望: {$expectedVersion}, 实际: {$currentVersion})，请重试",
                    ['expected' => $expectedVersion, 'actual' => $currentVersion]
                );
            }

            $newVersion = $currentVersion + 1;
            $data['version'] = $newVersion;

            $this->markingModel->where('id', $record->id)->update($data);
        } else {
            $data['subject'] = $subject;
            $data['version'] = 1;
            $data['create_time'] = $now;
            $this->markingModel->insert($data);
        }

        return $data['version'] ?? 1;
    }

    /**
     * {@inheritdoc}
     */
    public function recordHistory(
        string $subject,
        string $transitionName,
        string $fromPlace,
        string $toPlace,
        array $context = [],
        string $type = 'normal'
    ): void {
        $request = request();
        $data = [
            'subject'         => $subject,
            'transition_name' => $transitionName,
            'from_place'      => $fromPlace,
            'to_place'        => $toPlace,
            'context'         => json_encode($context, JSON_UNESCAPED_UNICODE),
            'type'            => $type,
            'remark'          => $context['remark'] ?? '',
            'ip'              => $request->ip(),
            'user_agent'      => $request->header('user-agent'),
            'create_time'      => date('Y-m-d H:i:s'),
        ];
        $userId = Session::get('user.id');
        if ($userId) {
            $data['user_id'] = $userId;
        }
        $this->historyModel->insert($data);
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(string $subject): int
    {
        $record = $this->markingModel->where('subject', $subject)->find();
        return $record ? (int)$record->version : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function setAssignee(string $subject, int $userId): void
    {
        $this->markingModel->where('subject', $subject)->update([
            'assigned_to' => $userId,
            'update_time'  => date('Y-m-d H:i:s'),
        ]);
    }
}
