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

/**
 * BatchSendResult — 批量发送结果值对象
 *
 * 聚合多通道或多接收者的发送结果，提供汇总统计和失败列表。
 */
class BatchSendResult
{
    /**
     * @var SendResult[] 各次发送的结果数组
     */
    public readonly array $results;

    /**
     * @param SendResult[] $results      各次发送的结果数组
     * @param int          $total        总发送数
     * @param int          $successCount 成功数
     * @param int          $failedCount  失败数
     */
    public function __construct(
        array $results,
        public readonly int $total,
        public readonly int $successCount,
        public readonly int $failedCount,
    ) {
        $this->results = $results;
    }

    /**
     * 构造方法（自动从 results 计算计数）
     *
     * @param SendResult[] $results 各次发送的结果数组
     * @return self
     */
    public static function fromResults(array $results): self
    {
        $total = count($results);
        $successCount = 0;
        $failedCount = 0;

        foreach ($results as $result) {
            if ($result->success) {
                $successCount++;
            } else {
                $failedCount++;
            }
        }

        return new self($results, $total, $successCount, $failedCount);
    }

    /**
     * 转换为数组
     *
     * @return array{total: int, success_count: int, failed_count: int, results: array}
     */
    public function toArray(): array
    {
        return [
            'total'         => $this->total,
            'success_count' => $this->successCount,
            'failed_count'  => $this->failedCount,
            'results'       => array_map(fn (SendResult $r) => $r->toArray(), $this->results),
        ];
    }

    /**
     * 获取所有失败的结果
     *
     * @return SendResult[]
     */
    public function getFailed(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (SendResult $r) => !$r->success
        ));
    }

    /**
     * 获取所有已入队的结果（异步模式）
     *
     * @return SendResult[]
     */
    public function getQueued(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (SendResult $r) => $r->status === SendResult::STATUS_QUEUED
        ));
    }

    /**
     * 获取所有已发送的结果（同步模式）
     *
     * @return SendResult[]
     */
    public function getSent(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (SendResult $r) => $r->status === SendResult::STATUS_SENT
        ));
    }
}
