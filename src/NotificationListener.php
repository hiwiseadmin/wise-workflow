<?php
declare(strict_types=1);

namespace wise\workflow;

/**
 * 通知监听器基类（Notification Listener）
 * 
 * 提供工作流事件到通知系统的桥梁
 * 业务层继承此类实现具体的通知发送逻辑
 * 
 * 使用方式：
 * 1. 继承此类覆盖 sendNotification() 方法
 * 2. 在事件系统中注册监听：
 *    Event::listen('workflow.*', [NotificationListener::class, 'handle']);
 * 
 * 或使用 Facade 快速注册：
 *    NotificationListener::register();
 * 
 * @package wise\workflow
 */
abstract class NotificationListener
{
    /**
     * 通知配置
     * @var array
     */
    protected $config;

    public function __construct()
    {
        $this->config = config('wise-workflow.notification', []);
    }

    /**
     * 事件处理入口
     *
     * @param array $payload 事件载荷
     * @return void
     */
    public function handle(array $payload): void
    {
        if (empty($this->config['enabled'])) {
            return;
        }

        $workflow   = $payload['workflow'] ?? null;
        $transition = $payload['transition'] ?? null;
        $subject    = $payload['subject'] ?? '';
        $context    = $payload['context'] ?? [];
        $eventName  = $payload['event_name'] ?? '';

        if (!$workflow) {
            return;
        }

        // 委托事件不要求 transition（delegate payload 不含 transition 键）
        if (strpos($eventName, '.delegate') !== false) {
            $this->handleDelegateEvent($eventName, $workflow, $subject, $context);
            return;
        }

        // 其余事件要求 transition
        if (!$transition) {
            return;
        }

        // 确定事件类型
        if (strpos($eventName, '.enter.') !== false) {
            $this->handleEnterEvent($eventName, $workflow, $subject, $context);
        } elseif (strpos($eventName, '.leave.') !== false) {
            $this->handleLeaveEvent($eventName, $workflow, $subject, $context);
        } elseif (strpos($eventName, '.guard_failed') !== false) {
            $this->handleGuardFailed($eventName, $workflow, $subject, $context);
        } elseif (strpos($eventName, '.completed') !== false) {
            $this->handleCompleted($eventName, $workflow, $subject, $context);
        } else {
            // 标准转换事件
            $this->handleTransitionEvent($eventName, $workflow, $transition, $subject, $context);
        }
    }

    /**
     * 处理状态转换通知
     */
    protected function handleTransitionEvent(
        string $eventName,
        Workflow $workflow,
        Transition $transition,
        string $subject,
        array $context
    ): void {
        $template = $this->config['templates']['transition'] ??
            '工作流 [{workflow}] 状态由 [{from}] 变更为 [{to}]';

        $fromPlaces = implode(',', $transition->getFrom());
        $toPlaces   = implode(',', $transition->getTo());
        $message    = str_replace(
            ['{workflow}', '{transition}', '{from}', '{to}', '{subject}'],
            [$workflow->getName(), $transition->getName(), $fromPlaces, $toPlaces, $subject],
            $template
        );

        $this->sendNotification($message, $this->config['channel'] ?? 'database', [
            'workflow'   => $workflow->getName(),
            'transition' => $transition->getName(),
            'subject'    => $subject,
            'from'       => $fromPlaces,
            'to'         => $toPlaces,
            'context'    => $context,
        ]);
    }

    /**
     * 处理委托通知
     */
    protected function handleDelegateEvent(
        string $eventName,
        Workflow $workflow,
        string $subject,
        array $context
    ): void {
        $template = $this->config['templates']['delegate'] ??
            '工作流 [{workflow}] 已由 [{from_user}] 委托给您处理';

        $message = str_replace(
            ['{workflow}', '{subject}', '{from_user}'],
            [$workflow->getName(), $subject, $context['from_user'] ?? ''],
            $template
        );

        $targetUser = $context['to_user'] ?? 0;

        $this->sendNotification($message, $this->config['channel'] ?? 'database', [
            'workflow'   => $workflow->getName(),
            'subject'    => $subject,
            'target_user' => $targetUser,
            'context'    => $context,
        ]);
    }

    /**
     * 处理进入状态事件
     */
    protected function handleEnterEvent(
        string $eventName,
        Workflow $workflow,
        string $subject,
        array $context
    ): void {
        // 子类可覆盖实现
    }

    /**
     * 处理离开状态事件
     */
    protected function handleLeaveEvent(
        string $eventName,
        Workflow $workflow,
        string $subject,
        array $context
    ): void {
        // 子类可覆盖实现
    }

    /**
     * 处理守卫失败
     */
    protected function handleGuardFailed(
        string $eventName,
        Workflow $workflow,
        string $subject,
        array $context
    ): void {
        // 子类可覆盖实现（如发送告警）
    }

    /**
     * 处理工作流完成
     */
    protected function handleCompleted(
        string $eventName,
        Workflow $workflow,
        string $subject,
        array $context
    ): void {
        // 子类可覆盖实现
    }

    /**
     * 发送通知（抽象方法，由子类实现）
     *
     * @param string $message 通知消息
     * @param string $channel 通知渠道（database/mail/sms/wechat）
     * @param array  $data    附加数据
     * @return void
     */
    abstract protected function sendNotification(string $message, string $channel, array $data): void;

    /**
     * 快速注册所有工作流事件监听
     * 
     * @param string $channel 通知渠道
     * @return void
     */
    public static function register(): void
    {
        // 由业务层在服务提供者中调用 Event::listen 注册具体监听器实例
        // 示例：
        // $listener = new AppNotificationListener();
        // \think\facade\Event::listen('workflow.*', [$listener, 'handle']);
    }
}

/**
 * 默认数据库通知监听器
 * 
 * 将通知写入 system_message 表
 */
class DatabaseNotificationListener extends NotificationListener
{
    protected function sendNotification(string $message, string $channel, array $data): void
    {
        try {
            $messageModel = new \think\Model();
            // 动态设置表名：优先使用配置
            $tableName = config('wise-workflow.notification.message_table', 'system_message');
            $messageModel->setTable($tableName);
            $messageModel->insert([
                'title'      => $data['workflow'] ?? '工作流通知',
                'content'    => $message,
                'type'       => 'workflow',
                'user_id'    => $data['target_user'] ?? 0,
                'is_read'    => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 通知发送失败不应影响主流程
        }
    }
}
