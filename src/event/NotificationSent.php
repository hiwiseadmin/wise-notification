<?php

declare(strict_types=1);

namespace wise\notification\event;

use wise\notification\NotificationManager;
use wise\notification\contract\Notifiable;
use wise\notification\contract\SendResult;

/**
 * NotificationSent — 发送成功事件
 *
 * 在通知成功发送后触发。
 * 监听器可在此阶段做：日志记录、统计上报、后续通知链等。
 */
class NotificationSent
{
    /**
     * @param NotificationManager $manager    管理器实例
     * @param string              $channel    发送通道
     * @param Notifiable          $notifiable 接收者
     * @param SendResult          $result     发送结果
     */
    public function __construct(
        public readonly NotificationManager $manager,
        public readonly string $channel,
        public readonly Notifiable $notifiable,
        public readonly SendResult $result,
    ) {}
}
