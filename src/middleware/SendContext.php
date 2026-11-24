<?php

declare(strict_types=1);

namespace wise\notification\middleware;

use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;

/**
 * SendContext — 发送上下文值对象
 *
 * 封装单次发送的完整上下文信息（通道、接收者、消息、附加数据），
 * 在中间件管道中传递。通过 __get/__set 魔术方法扩展 extra 属性。
 */
class SendContext
{
    /**
     * @param string     $channel    发送通道
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @param array<string, mixed> $extra 附加数据（可动态扩展）
     */
    public function __construct(
        public readonly string $channel,
        public readonly Notifiable $notifiable,
        public readonly Message $message,
        /** @var array<string, mixed> 附加数据 */
        public array $extra = [],
    ) {}

    /**
     * 转换为数组
     *
     * @return array{channel: string, notifiable: string, message: array, extra: array}
     */
    public function toArray(): array
    {
        return [
            'channel'    => $this->channel,
            'notifiable' => get_class($this->notifiable),
            'message'    => $this->message->toArray(),
            'extra'      => $this->extra,
        ];
    }

    /**
     * 魔术方法：读取 extra 中的键值
     *
     * @param string $name 键名
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->extra[$name] ?? null;
    }

    /**
     * 魔术方法：设置 extra 中的键值
     *
     * @param string $name  键名
     * @param mixed  $value 值
     */
    public function __set(string $name, mixed $value): void
    {
        $this->extra[$name] = $value;
    }
}
