<?php
declare(strict_types=1);

namespace wise\workflow\listener;

/**
 * 默认数据库通知监听器
 *
 * 将通知写入 wise-notification 的 user_notifications 表，
 * 取代旧的 system_message 直接写入方式。
 *
 * 独立成文件是为了符合 PSR-4（类名必须与文件名一致）：此前该类与
 * NotificationListener 基类写在同一个文件中，冷启动时
 * `class_exists(DatabaseNotificationListener::class)` 为 false，
 * `new DatabaseNotificationListener()` 会抛 Error 并被 catch 静默吞掉，
 * 表现为「notification.enabled = true 但通知永远发不出」。
 *
 * @since 2026-08-09 接入 wise-notification 包
 */
class DatabaseNotificationListener extends NotificationListener
{
    protected function sendNotification(string $message, string $channel, array $data): void
    {
        try {
            $targetUser = $data['target_user'] ?? 0;
            if ($targetUser <= 0) {
                return;
            }

            \think\facade\Db::name('user_notifications')->insert([
                'user_id'     => $targetUser,
                'title'       => $data['workflow'] ?? '工作流通知',
                'content'     => $message,
                'type'        => 'workflow',
                'data'        => json_encode($data, JSON_UNESCAPED_UNICODE),
                'is_read'     => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
