<?php

declare(strict_types=1);

namespace wise\notification\event;

use wise\notification\NotificationManager;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;

/**
 * NotificationSending — 发送前事件
 *
 * 在通知发送前触发，可通过调用 halt() 方法阻止发送。
 * 监听器可在此阶段做：内容审核、敏感词过滤、A/B 测试路由等。
 */
class NotificationSending
{
    /**
     * @param NotificationManager $manager    管理器实例
     * @param string              $channel    发送通道
     * @param Notifiable          $notifiable 接收者
     * @param Message             $message    消息体
     * @param bool                $halted     是否已阻止发送
     */
    public function __construct(
        public readonly NotificationManager $manager,
        public readonly string $channel,
        public readonly Notifiable $notifiable,
        public readonly Message $message,
        public bool $halted = false,
    ) {}

    /**
     * 阻止此次发送
     *
     * 调用后 Manager 将中止发送流程，返回失败结果。
     */
    public function halt(): void
    {
        $this->halted = true;
    }
}
