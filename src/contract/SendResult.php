<?php

declare(strict_types=1);

namespace wise\notification\contract;

/**
 * SendResult — 单次发送结果值对象
 *
 * 封装单通道发送的结果信息：成功与否、通道名称、响应、错误、耗时、状态。
 */
class SendResult
{
    /** 状态常量：已入队（异步模式） */
    public const STATUS_QUEUED = 'queued';

    /** 状态常量：已发送（同步/驱动直接返回） */
    public const STATUS_SENT = 'sent';

    /** 状态常量：发送失败 */
    public const STATUS_FAILED = 'failed';

    /**
     * @param bool    $success    是否成功
     * @param string  $channel    发送通道
     * @param ?string $response   通道返回的原始响应
     * @param ?string $error      错误信息（失败时）
     * @param int     $durationMs 发送耗时（毫秒）
     * @param ?string $status     状态标识（queued/sent/failed），null 表示未指定
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly ?string $response = null,
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
        public readonly ?string $status = null,
    ) {}

    /**
     * 转换为数组
     *
     * @return array{success: bool, channel: string, response: string|null, error: string|null, duration_ms: int, status: string|null}
     */
    public function toArray(): array
    {
        return [
            'success'     => $this->success,
            'channel'     => $this->channel,
            'response'    => $this->response,
            'error'       => $this->error,
            'duration_ms' => $this->durationMs,
            'status'      => $this->status,
        ];
    }
}
