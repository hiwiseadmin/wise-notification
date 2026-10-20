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

            // 反序列化 Notifiable
            $notifiable = $this->resolveNotifiable($notifiableType, $notifiableId);
            if ($notifiable === null) {
                throw new \RuntimeException(
                    "无法解析 Notifiable: {$notifiableType}#{$notifiableId}"
                );
            }

            // 反序列化 Message
            $message = new Message(
                title: $messageData['title'] ?? '',
                content: $messageData['content'] ?? '',
                data: $messageData['data'] ?? [],
            );

            // 获取 Manager 并执行调度
            /** @var NotificationManager $manager */
            $manager = app()->make(NotificationManager::class);

            // 渠道级断路器 guard + 防御性重试
            $breaker = $this->getChannelBreaker($channel);
            if ($breaker !== null && !$breaker->isAvailable()) {
                throw new ServiceUnavailableException(channel: $channel);
            }

            // 不再用 \retry 包裹 dispatch()：dispatch 内部已捕获驱动异常并返回
            // SendResult，包裹只会在「driver 已投递成功、落库/事件阶段抛异常」时
            // 重跑整条链路，造成同一条短信/邮件重复投递；且对真正的驱动失败毫无
            // 作用（不抛异常）。驱动层重试交由下方队列退避重试负责。
            $result = $manager->dispatch(
                $channel,
                $notifiable,
                $message,
                (int) ($data['history_id'] ?? 0) ?: null
            );

            // 驱动失败不会抛出异常，必须显式判定，否则会走"成功"分支
            if (!$result->success) {
                throw new \RuntimeException(
                    sprintf('通知发送失败[%s]: %s', $channel, $result->error ?? '未知错误')
                );
            }

            $breaker?->reportSuccess();

            // 更新历史记录
            $this->updateHistory($data, $result);

            // 成功：删除任务
            $job->delete();

        } catch (\Throwable $e) {
            // 熔断开启导致的失败已在 isAvailable() 处短路，不重复计入失败次数
            if ($breaker !== null && !($e instanceof CircuitBreakerExceptionInterface)) {
                $breaker->reportFailure();
            }

            $retry = $this->resolveRetryState($data);

            if ($retry['attempt'] >= $retry['max_attempts']) {
                // 超出最大重试：更新为死信状态并结束（复用既有历史行）
                $this->recordFailure($data, $e, 'dead');
                $job->delete();
                return;
            }

            // 记录失败（复用既有历史行，避免每次重试新增记录）
            $this->recordFailure($data, $e);

            // 完整回写重试状态，否则下一轮退避梯度会丢失
            $data['retry'] = $retry;

            // 发布延迟重试任务（退避梯度复用 wise-support BackoffStrategy）
            $queue = app()->make('queue');
            if ($queue === null || $queue === false) {
                throw new \RuntimeException('队列服务不可用，无法重新发布延迟任务', 0, $e);
            }

            $queue->later(
                $this->backoffSeconds($retry['attempt'], $retry['backoff']),
                self::class,
                $data,
                config('wise-notification.queue.queue', 'notification'),
            );

            // 标记旧任务完成（已重新发布）
            $job->delete();
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
        // class_exists + is_subclass_of 双重校验：类型来自队列载荷，
        // 必须在调用 $type::find() 之前确认其实现了 Notifiable，
        // 否则可被诱导静态实例化任意模型（越权读取）。
        if (!class_exists($type) || !is_subclass_of($type, Notifiable::class)) {
            return null;
        }
        /** @var \think\Model $instance */
        $instance = $type::find($id);
        if ($instance instanceof Notifiable) {
            return $instance;
        }
        return null;
    }

    private function updateHistory(array $jobData, \wise\notification\contract\SendResult $result): void
    {
        // 更新路径同样经过 sanitize（脱敏 + 截断）
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

        // 审计：重试 / 死信属策略事件，writing 到 notification_audit_logs
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
     * 按渠道创建/获取断路器实例（key_prefix=wise_notification:cb）
     *
     * 状态存储使用 circuit.cache_store（null = 应用默认缓存通道），不再复用
     * queue.connection —— 队列连接名未必是合法 cache store，且两者语义无关。
     * 缓存通道不可用时降级为「不熔断」（返回 null）并记录日志：
     * 熔断属保护性措施，不应因其自身故障阻断通知投递。
     */
    private function getChannelBreaker(string $channel): ?CircuitBreaker
    {
        static $breakers = [];

        // 注意用 array_key_exists：失败降级会把值缓存为 null
        if (array_key_exists($channel, $breakers)) {
            return $breakers[$channel];
        }

        $cfg = config('wise-notification.circuit', []);

        try {
            $store = $cfg['cache_store'] ?? null;
            $breakers[$channel] = new CircuitBreaker(
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
            $breakers[$channel] = null;
        }

        return $breakers[$channel];
    }
}
