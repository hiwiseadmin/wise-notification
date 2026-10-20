<?php

declare(strict_types=1);

namespace wise\notification\channel;

use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * ChannelDriver — 通道驱动接口
 *
 * 所有通知通道（邮件、短信、钉钉、站内信等）必须实现此接口。
 * 每个驱动负责将 Message 通过特定渠道发送给 Notifiable。
 */
interface ChannelDriver
{
    /**
     * 发送消息
     *
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @return SendResult 发送结果
     */
    public function send(Notifiable $notifiable, Message $message): SendResult;

    /**
     * 获取通道名称
     *
     * @return string 通道标识（email/sms/database/dingtalk）
     */
    public function getName(): string;
}
