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

        // 兼容两种形态：模型中 `'marking' => 'json'` 会自动解码为数组，
        // 早期/其它连接返回原始 JSON 字符串时再手工解码。
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

            // 乐观锁检查（消息保留「乐观锁冲突」关键词：Engine 依赖该关键词决定是否重试）
            // 安全（审计阶段 1 / E-1）：subject 与版本号只进 data/日志，不进响应消息
            if ($expectedVersion > 0 && $currentVersion !== $expectedVersion) {
                throw new WorkflowException(
                    '乐观锁冲突：版本不匹配，请重试',
                    ['subject' => $subject, 'expected' => $expectedVersion, 'actual' => $currentVersion]
                );
            }

            $newVersion = $currentVersion + 1;
            $data['version'] = $newVersion;

            // 数据库层面乐观锁：WHERE 条件同时包含 id 和 version，
            // 防止 TOCTOU 竞态导致并发覆盖
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
            // 新记录：如果调用方指定了 expectedVersion（>0），说明预期记录应存在
            // 此时记录不存在即为并发冲突，应抛出异常
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
        // 安全获取 request 对象（CLI 模式下可能不可用）
        $request = null;
        try {
            $request = request();
        } catch (\Throwable $e) {
            // CLI 环境下 request() 可能抛出异常，静默忽略
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

        // 安全获取 Session 用户 ID（CLI 或 Session 未初始化时可能不可用）
        // Session key 可通过 wise-workflow.user_session_key 配置，默认 'user.id'
        try {
            $sessionKey = config('wise-workflow.user_session_key', 'user.id');
            $userId = Session::get($sessionKey);
            if ($userId) {
                $data['user_id'] = $userId;
            }
        } catch (\Throwable $e) {
            // CLI 或 Session 未初始化时静默忽略
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
