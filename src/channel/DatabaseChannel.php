<?php

declare(strict_types=1);

namespace wise\notification\channel;

use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;
use wise\notification\model\UserNotification;

/**
 * DatabaseChannel — 站内信通道驱动
 *
 * 将通知写入 user_notifications 表，用户可在站内查看。
 * 配置来源：config('wise-notification.channels.database')（当前为空，无额外参数）。
 */
class DatabaseChannel implements ChannelDriver
{
    /**
     * 获取通道名称
     *
     * @return string
     */
    public function getName(): string
    {
        return 'database';
    }

    /**
     * 发送站内信通知
     *
     * 从 notifiable 获取 user_id，创建 UserNotification 记录。
     *
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @return SendResult
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $startTime = microtime(true);

        try {
            $userId = $notifiable->routeNotificationFor('database');

            // routeNotificationFor 可能返回字符串或数组，取字符串形式
            if (is_array($userId)) {
                $userId = (int) ($userId[0] ?? 0);
            } else {
                $userId = (int) $userId;
            }

            if ($userId <= 0) {
                throw new \RuntimeException('站内信发送失败：无法获取有效的用户 ID');
            }

            $notification = new UserNotification();
            $notification->save([
                'user_id'    => $userId,
                'title'      => $message->title,
                'content'    => $message->content,
                'type'       => $message->get('type', 'system'),
                'data'       => json_encode($message->data, JSON_UNESCAPED_UNICODE),
                'is_read'    => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new SendResult(
                success: true,
                channel: 'database',
                response: json_encode(['id' => $notification->getAttr('id')], JSON_UNESCAPED_UNICODE),
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'database',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }
}
