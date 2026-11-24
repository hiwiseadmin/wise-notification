<?php

declare(strict_types=1);

namespace wise\notification\facade;

use think\Facade;

/**
 * Notification 门面类
 *
 * 提供静态调用入口，代理所有请求到 NotificationManager。
 *
 * @method static \wise\notification\NotificationManager channel(string|array $channels)
 * @method static \wise\notification\NotificationManager sync()
 * @method static \wise\notification\NotificationManager template(string $code)
 * @method static \wise\notification\NotificationManager priority(string $priority)
 * @method static \wise\notification\NotificationManager delay(int $seconds)
 * @method static \wise\notification\contract\SendResult|\wise\notification\contract\BatchSendResult|null send(\wise\notification\contract\Notifiable $notifiable, \wise\notification\contract\Message|array $message)
 * @method static \wise\notification\contract\BatchSendResult batch(array $notifiables, \wise\notification\contract\Message|array $message, array $options = [])
 * @method static \wise\notification\contract\SendResult dispatch(string $channel, \wise\notification\contract\Notifiable $notifiable, \wise\notification\contract\Message $message)
 * @method static \wise\notification\contract\Message createMessage(string $title = '', string $content = '', array $data = [])
 * @method static void extend(string $name, \wise\notification\channel\ChannelDriver $driver)
 * @method static void extendMiddleware(\wise\notification\middleware\NotificationMiddleware $middleware)
 *
 * @see \wise\notification\NotificationManager
 */
class Notification extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\notification\NotificationManager::class;
    }
}
