<?php
declare(strict_types=1);

namespace wise\workflow\store;

use think\facade\Session;
use wise\workflow\contract\MarkingStoreInterface;
use wise\workflow\exception\WorkflowException;
use wise\workflow\model\WorkflowMarking;
use wise\workflow\model\WorkflowHistory;

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
        $this->markingModel = $markingModel ?: new WorkflowMarking();
        $this->historyModel = $historyModel ?: new WorkflowHistory();
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

        $marking = $record->marking;

        if (is_array($marking)) {
            return $marking;
        }

        return json_decode((string) $marking, true) ?: [];
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

        $subjectType = $context['subject_type'] ?? null;
        if ($subjectType === null && strpos($subject, ':') !== false) {
            [$subjectType] = explode(':', $subject, 2);
        }
        if ($subjectType) {
            $data['subject_type'] = $subjectType;
        }

        if (isset($context['definition_version'])) {
            $data['definition_version'] = $context['definition_version'];
        }

        $data['entered_at'] = $now;

        if ($record) {
            $currentVersion = (int)$record->version;

            if ($expectedVersion > 0 && $currentVersion !== $expectedVersion) {
                throw new WorkflowException(
                    '乐观锁冲突：版本不匹配，请重试',
                    ['subject' => $subject, 'expected' => $expectedVersion, 'actual' => $currentVersion]
                );
            }

            $newVersion = $currentVersion + 1;
            $data['version'] = $newVersion;

            $affected = $this->markingModel
                ->where('id', $record->id)
                ->where('version', $currentVersion)
                ->update($data);

            if ($affected === 0) {
                throw new WorkflowException(
                    '乐观锁冲突：写入时版本已变更，请重试',
                    ['subject' => $subject, 'expected' => $expectedVersion, 'actual' => $currentVersion]
                );
            }
        } else {
            if ($expectedVersion > 0) {
                throw new WorkflowException(
                    '乐观锁冲突：实例不存在或已被删除，请重试',
                    ['subject' => $subject, 'expected' => $expectedVersion]
                );
            }
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
        $request = null;
        try {
            $request = request();
        } catch (\Throwable $e) {
        }

        $data = [
            'subject'         => $subject,
            'transition_name' => $transitionName,
            'from_place'      => $fromPlace,
            'to_place'        => $toPlace,
            'context'         => json_encode($context, JSON_UNESCAPED_UNICODE),
            'type'            => $type,
            'remark'          => $context['remark'] ?? '',
            'ip'              => $request ? $request->ip() : '',
            'user_agent'      => $request ? $request->header('user-agent') : '',
            'create_time'      => date('Y-m-d H:i:s'),
        ];

        try {
            $sessionKey = config('wise-workflow.user_session_key', 'user.id');
            $userId = Session::get($sessionKey);
            if ($userId) {
                $data['user_id'] = $userId;
            }
        } catch (\Throwable $e) {
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

    /**
     * {@inheritdoc}
     */
    public function getDefinitionVersion(string $subject): int
    {
        $record = $this->markingModel->where('subject', $subject)->find();
        return $record ? (int)($record->definition_version ?? 0) : 0;
    }
}
