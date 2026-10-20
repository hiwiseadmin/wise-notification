<?php

declare(strict_types=1);

namespace wise\notification\event;

use wise\notification\NotificationManager;
use wise\notification\contract\Notifiable;
use wise\notification\contract\SendResult;

/**
 * NotificationFailed — 发送失败事件
 *
 * 在通知发送失败后触发。
 * 监听器可在此阶段做：告警通知、自动重试、记录错误日志等。
 */
class NotificationFailed
{
    /**
     * @param NotificationManager $manager    管理器实例
     * @param string              $channel    发送通道
     * @param Notifiable          $notifiable 接收者
     * @param SendResult          $result     发送结果（含错误信息）
     */
    public function __construct(
        public readonly NotificationManager $manager,
        public readonly string $channel,
        public readonly Notifiable $notifiable,
        public readonly SendResult $result,
    ) {}
}
