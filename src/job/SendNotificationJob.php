<?php

declare(strict_types=1);

namespace wise\notification\job;

use think\queue\Job;
use wise\notification\NotificationManager;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\model\NotificationHistory;

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
     */
    public function fire(Job $job, array $data): void
    {
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
            $result = $manager->dispatch($channel, $notifiable, $message);

            // 更新历史记录
            $this->updateHistory($data, $result);

            // 成功：删除任务
            $job->delete();

        } catch (\Throwable $e) {
            // 记录失败历史
            $this->recordFailure($data, $e);

            // 指数退避重试
            $retryInfo = $data['retry'] ?? ['attempt' => 0, 'max_attempts' => 3, 'backoff' => [1, 5, 15]];
            $currentAttempt = ($retryInfo['attempt'] ?? 0) + 1;
            $maxAttempts = $retryInfo['max_attempts'] ?? 3;
            $backoff = $retryInfo['backoff'] ?? [1, 5, 15];

            if ($currentAttempt >= $maxAttempts) {
                // 超出最大重试：记录死信并删除
                $this->recordDeadLetter($data, $e);
                $job->delete();
                return;
            }

            // 计算退避延迟（秒）
            $backoffIdx = min($currentAttempt - 1, count($backoff) - 1);
            $delayMinutes = $backoff[$backoffIdx];
            $delaySeconds = $delayMinutes * 60;

            // 更新重试信息
            $data['retry']['attempt'] = $currentAttempt;

            // 发布延迟重试任务
            $queueName = config('wise-notification.queue.queue', 'notification');
            /** @var \think\Queue $queue */
            $queue = app()->make('queue');
            $queue->later($delaySeconds, self::class, $data, $queueName);

            // 标记旧任务完成（已重新发布）
            $job->delete();
        }
    }

    private function resolveNotifiable(string $type, string|int $id): ?Notifiable
    {
        if (empty($type) || empty($id)) {
            return null;
        }
        if (!class_exists($type)) {
            return null;
        }
        $instance = (new $type())->find($id);
        if ($instance instanceof Notifiable) {
            return $instance;
        }
        return null;
    }

    private function updateHistory(array $jobData, \wise\notification\contract\SendResult $result): void
    {
        $updateData = [
            'status'      => $result->success ? 'sent' : 'failed',
            'response'    => $result->response,
            'error'       => $result->error,
            'duration_ms' => $result->durationMs,
        ];

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
     * 记录失败历史
     */
    private function recordFailure(array $data, \Throwable $e): void
    {
        NotificationHistory::record([
            'channel'         => $data['channel'] ?? 'unknown',
            'notifiable_type' => $data['notifiable_type'] ?? '',
            'notifiable_id'   => (string) ($data['notifiable_id'] ?? ''),
            'message_type'    => $data['message_type'] ?? 'direct',
            'message_payload' => json_encode($data['message'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'          => 'failed',
            'error'           => $e->getMessage(),
            'create_time'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 记录死信（超出最大重试次数）
     */
    private function recordDeadLetter(array $data, \Throwable $e): void
    {
        $deadQueue = config('wise-notification.retry.dead_queue', 'notification_dead');

        // 记录死信历史
        NotificationHistory::record([
            'channel'         => $data['channel'] ?? 'unknown',
            'notifiable_type' => $data['notifiable_type'] ?? '',
            'notifiable_id'   => (string) ($data['notifiable_id'] ?? ''),
            'message_type'    => $data['message_type'] ?? 'direct',
            'message_payload' => json_encode($data['message'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'          => 'dead',
            'error'           => 'Dead letter: ' . $e->getMessage(),
            'create_time'      => date('Y-m-d H:i:s'),
        ]);
    }
}
