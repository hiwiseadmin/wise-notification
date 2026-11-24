<?php

declare(strict_types=1);

namespace wise\notification\job;

use think\facade\Cache;
use think\queue\Job;
use wise\notification\NotificationManager;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\exception\ServiceUnavailableException;
use wise\notification\model\NotificationAuditLog;
use wise\notification\model\NotificationHistory;
use wise\support\backoff\BackoffStrategy;
use wise\support\circuit\CircuitBreaker;
use wise\support\circuit\CircuitBreakerExceptionInterface;

/**
 * SendNotificationJob — 异步发送队列任务
 *
 * 从队列数据反序列化 notifiable 和 message，调用 Manager::dispatch() 完成发送。
 * 支持指数退避重试策略和优先级。
 */
class SendNotificationJob
{
    /**
     * 断路器实例池（实例级）
     *
     * 不用函数级 static：队列 Worker 常驻时 static 会让 CircuitBreaker 及其内部的
     * Cache store（phpredis 连接）被所有协程/任务共享 —— 连接不是协程安全的，
     * 且 circuit.* 配置会被固化在首次调用时的取值上。
     *
     * @var array<string, CircuitBreaker|null>
     */
    private array $breakers = [];

    /**
     * 执行队列任务
     *
     * 失败链路：通道驱动内部会捕获异常并返回 SendResult（不抛出），
     * 因此必须在 dispatch 之后显式判定 $result->success，失败时抛出交由 catch
     * 统一处理（断路器计数 + 队列退避重试 + 死信）。
     * 否则失败会被误判为成功：任务被删除、断路器上报成功、永不重试。
     */
    public function fire(Job $job, array $data): void
    {
        $breaker = null;

        try {
            $channel        = $data['channel'] ?? 'email';
            $notifiableType = $data['notifiable_type'] ?? '';
            $notifiableId   = $data['notifiable_id'] ?? '';
            $messageData    = $data['message'] ?? [];

            $historyId = (int) ($data['history_id'] ?? 0);
            if ($historyId > 0 && !$this->claimHistory($historyId)) {
                $job->delete();

                return;
            }

            $notifiable = $this->resolveNotifiable($notifiableType, $notifiableId);
            if ($notifiable === null) {
                throw new \RuntimeException(
                    "无法解析 Notifiable: {$notifiableType}#{$notifiableId}"
                );
            }

            $message = new Message(
                title: $messageData['title'] ?? '',
                content: $messageData['content'] ?? '',
                data: $messageData['data'] ?? [],
            );

            $manager = app()->make(NotificationManager::class);

            $breaker = $this->getChannelBreaker($channel);
            if ($breaker !== null && !$breaker->isAvailable()) {
                throw new ServiceUnavailableException(channel: $channel);
            }

            $result = $manager->dispatch(
                $channel,
                $notifiable,
                $message,
                (int) ($data['history_id'] ?? 0) ?: null
            );

            if (!$result->success) {
                throw new \RuntimeException(
                    sprintf('通知发送失败[%s]: %s', $channel, $result->error ?? '未知错误')
                );
            }

            $breaker?->reportSuccess();

            $this->updateHistory($data, $result);

            $job->delete();

        } catch (\Throwable $e) {
            if ($breaker !== null && !($e instanceof CircuitBreakerExceptionInterface)) {
                $breaker->reportFailure();
            }

            $retry = $this->resolveRetryState($data);

            if ($retry['attempt'] >= $retry['max_attempts']) {
                $this->recordFailure($data, $e, 'dead');
                $job->delete();
                return;
            }

            $this->recordFailure($data, $e);

            $data['retry'] = $retry;

            $queue = app()->make('queue');
            if ($queue === null || $queue === false) {
                throw new \RuntimeException('队列服务不可用，无法重新发布延迟任务', 0, $e);
            }

            $job->delete();

            $queue->later(
                $this->backoffSeconds($retry['attempt'], $retry['backoff']),
                self::class,
                $data,
                config('wise-notification.queue.queue', 'notification'),
            );
        }
    }

    /**
     * 解析重试状态（次数、上限、退避梯度）
     *
     * @param array $data 队列任务数据
     * @return array{attempt: int, max_attempts: int, backoff: array}
     */
    private function resolveRetryState(array $data): array
    {
        $retry = is_array($data['retry'] ?? null) ? $data['retry'] : [];

        return [
            'attempt'      => (int) ($retry['attempt'] ?? 0) + 1,
            'max_attempts' => (int) ($retry['max_attempts']
                ?? config('wise-notification.retry.max_attempts', 5)),
            'backoff'      => (array) ($retry['backoff']
                ?? config('wise-notification.retry.backoff', [1, 5, 15, 60, 240])),
        ];
    }

    /**
     * 计算第 N 次重试的延迟秒数
     *
     * 复用 wise-support BackoffStrategy::custom()：按梯度取值，
     * 超出梯度长度后沿用最后一个值（与 retry.backoff 配置语义一致）。
     *
     * @param int   $attempt        重试序号（从 1 开始）
     * @param array $backoffMinutes 退避梯度（分钟）
     */
    private function backoffSeconds(int $attempt, array $backoffMinutes): int
    {
        $delaysMs = [];
        foreach ($backoffMinutes as $minutes) {
            $delaysMs[] = max(0, (int) $minutes) * 60_000;
        }
        if ($delaysMs === []) {
            $delaysMs = [60_000];
        }

        return (int) ceil(BackoffStrategy::custom($delaysMs)->delayForAttempt($attempt) / 1000);
    }

    private function resolveNotifiable(string $type, string|int $id): ?Notifiable
    {
        if (empty($type) || empty($id)) {
            return null;
        }
        if (!class_exists($type) || !is_subclass_of($type, Notifiable::class)) {
            return null;
        }
        $instance = $type::find($id);
        if ($instance instanceof Notifiable) {
            return $instance;
        }
        return null;
    }

    private function updateHistory(array $jobData, \wise\notification\contract\SendResult $result): void
    {
        $updateData = NotificationHistory::sanitize([
            'status'      => $result->success ? 'sent' : 'failed',
            'response'    => $result->response,
            'error'       => $result->error,
            'duration_ms' => $result->durationMs,
        ]);

        $historyId = $jobData['history_id'] ?? null;
        if ($historyId !== null) {
            $history = NotificationHistory::find((int) $historyId);
            if ($history) {
                $history->save($updateData);
            }
            return;
        }

        $notifiableType = $jobData['notifiable_type'] ?? '';
        $notifiableId   = (string) ($jobData['notifiable_id'] ?? '');
        if (empty($notifiableType) || empty($notifiableId)) {
            return;
        }

        $history = NotificationHistory::byChannel($result->channel)
            ->byNotifiable($notifiableType, $notifiableId)
            ->byStatus('pending')
            ->order('id', 'desc')
            ->find();

        if ($history) {
            $history->save($updateData);
        }
    }

    /**
     * 记录失败 / 死信
     *
     * 优先复用既有历史行（history_id）：dispatch 已把该行更新为 failed，
     * 此处只更新状态与错误，避免每次重试都新增一行导致历史表膨胀。
     * 仅在缺少 history_id（历史数据或异常入队）时兜底插入；
     * 脱敏与截断统一由 NotificationHistory::sanitize()/record() 执行。
     *
     * @param array      $data   队列任务数据
     * @param \Throwable $e      失败异常
     * @param string     $status 目标状态（failed / dead）
     */
    private function recordFailure(array $data, \Throwable $e, string $status = 'failed'): void
    {
        $error = $status === 'dead' ? 'Dead letter: ' . $e->getMessage() : $e->getMessage();

        $this->writeAudit($status === 'dead' ? 'dead' : 'retry', $data, $error);

        $historyId = (int) ($data['history_id'] ?? 0);
        if ($historyId > 0) {
            $history = NotificationHistory::find($historyId);
            if ($history) {
                $history->save(NotificationHistory::sanitize([
                    'status' => $status,
                    'error'  => $error,
                ]));
                return;
            }
        }

        NotificationHistory::record([
            'channel'         => $data['channel'] ?? 'unknown',
            'notifiable_type' => $data['notifiable_type'] ?? '',
            'notifiable_id'   => (string) ($data['notifiable_id'] ?? ''),
            'message_type'    => $data['message_type'] ?? 'direct',
            'message_payload' => NotificationHistory::encodePayload((array) ($data['message'] ?? [])),
            'status'          => $status,
            'error'           => $error,
            'create_time'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 写入通知审计日志（重试 / 死信等策略事件）
     *
     * 正常发送链路已由 notification_histories 记录，审计表只承载
     * histories 无法表达为独立动作的事件。审计失败不影响主流程。
     */
    private function writeAudit(string $action, array $data, string $error): void
    {
        try {
            NotificationAuditLog::log([
                'action'          => $action,
                'channel'         => (string) ($data['channel'] ?? 'unknown'),
                'notifiable_type' => (string) ($data['notifiable_type'] ?? ''),
                'notifiable_id'   => (string) ($data['notifiable_id'] ?? ''),
                'message_type'    => (string) ($data['message_type'] ?? 'direct'),
                'status'          => $action === 'dead' ? 'dead' : 'failed',
                'error'           => $error,
                'context'         => NotificationHistory::encodePayload([
                    'history_id' => $data['history_id'] ?? null,
                    'batch_id'   => $data['batch_id'] ?? null,
                    'attempt'    => $data['retry']['attempt'] ?? null,
                    'is_batch'   => $data['is_batch'] ?? false,
                ]),
                'create_time'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $ex) {
            error_log('[wise-notification] 审计日志写入失败: ' . $ex->getMessage());
        }
    }

    /**
     * 抢占历史记录（幂等闸门）
     *
     * 用「状态条件更新」实现原子抢占：只有处于 pending（首次投递）或
     * failed（重试投递）的记录能被抢到，抢到的任务才继续发送。
     * 并发/重复投递时只有一个任务能更新成功，其余直接丢弃。
     *
     * 说明：进程在「抢占成功 → 发送完成」之间被 kill 时，记录会停留在
     * sending；这是 at-most-once 的必然代价（宁可人工补发，也不重复扣费），
     * 可通过 `wise:notification:retry-failed` 之外的巡检命令重置。
     *
     * @param int $historyId 历史记录 ID
     */
    private function claimHistory(int $historyId): bool
    {
        try {
            $affected = NotificationHistory::where('id', $historyId)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'sending']);

            return (int) $affected > 0;
        } catch (\Throwable $e) {
            error_log('[wise-notification] 历史记录抢占失败，跳过幂等校验: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * 按渠道创建/获取断路器实例（key_prefix=wise_notification:cb）
     *
     * 状态存储使用 circuit.cache_store（null = 应用默认缓存通道），不再复用
     * queue.connection —— 队列连接名未必是合法 cache store，且两者语义无关。
     * 缓存通道不可用时降级为「不熔断」（返回 null）并记录日志：
     * 熔断属保护性措施，不应因其自身故障阻断通知投递。
     */
    private function getChannelBreaker(string $channel): ?CircuitBreaker
    {
        if (array_key_exists($channel, $this->breakers)) {
            return $this->breakers[$channel];
        }

        $cfg = config('wise-notification.circuit', []);

        try {
            $store = $cfg['cache_store'] ?? null;
            $this->breakers[$channel] = new CircuitBreaker(
                $channel,
                Cache::store(is_string($store) && $store !== '' ? $store : null),
                [
                    'failure_threshold' => $cfg['failure_threshold'] ?? 5,
                    'timeout'           => $cfg['timeout'] ?? 60,
                    'key_prefix'        => $cfg['key_prefix'] ?? 'wise_notification:cb',
                ]
            );
        } catch (\Throwable $e) {
            error_log(
                "[wise-notification] 断路器缓存通道不可用，{$channel} 渠道降级为不熔断: " . $e->getMessage()
            );
            $this->breakers[$channel] = null;
        }

        return $this->breakers[$channel];
    }
}
