<?php

declare(strict_types=1);

namespace wise\notification\middleware;

use wise\notification\contract\SendResult;
use wise\notification\preference\PreferenceManager;

/**
 * PreferenceCheckMiddleware — 通知偏好检查中间件
 *
 * 在发送前检查用户偏好：
 * 1. 通道是否被用户关闭
 * 2. 是否在免打扰时段（且非紧急消息）
 * 3. 消息类型是否被用户订阅
 *
 * 如果检查不通过，直接返回失败结果（短路），不再执行后续中间件和驱动发送。
 */
class PreferenceCheckMiddleware implements NotificationMiddleware
{
    /**
     * 处理中间件
     */
    public function handle(SendContext $context, callable $next): SendResult
    {
        $notifiable = $context->notifiable;
        $channel    = $context->channel;
        $message    = $context->message;

        $preferenceManager = new PreferenceManager();

        $blockResult = $preferenceManager->shouldBlock(
            $notifiable,
            $channel,
            $message,
            $message->get('message_type', 'general'),
        );

        if ($blockResult['blocked']) {
            return new SendResult(
                success: false,
                channel: $channel,
                error: 'Blocked by preference: ' . $blockResult['reason'],
            );
        }

        return $next($context);
    }
}
