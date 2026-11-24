<?php

declare(strict_types=1);

namespace wise\notification\contract;

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
