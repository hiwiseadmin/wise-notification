<?php

declare(strict_types=1);

namespace wise\notification\contract;

/**
 * Message — 消息值对象
 *
 * 封装通知的标题、正文及附加数据，所有通道均可消费。
 * 属性均为 public readonly，构造后不可变。
 */
class Message
{
    // 优先级常量
    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW    = 'low';

    /**
     * @param string $title   消息标题
     * @param string $content 消息正文
     * @param array  $data    附加数据（模板变量、通道特定参数等）
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $content = '',
        public readonly array $data = [],
    ) {}

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'title'   => $this->title,
            'content' => $this->content,
            'data'    => $this->data,
        ];
    }

    /**
     * 获取附加数据的指定键值
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 获取消息优先级
     */
    public function getPriority(): string
    {
        return $this->data['_priority'] ?? self::PRIORITY_NORMAL;
    }

    /**
     * 是否为紧急消息
     */
    public function isUrgent(): bool
    {
        return $this->getPriority() === self::PRIORITY_URGENT;
    }
}
