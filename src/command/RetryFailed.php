<?php

declare(strict_types=1);

namespace wise\notification\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\notification\model\NotificationHistory;

/**
 * RetryFailed — 失败通知重试命令
 *
 * 从历史表中读取失败记录，重新投递到队列。
 *
 * 用法：
 *   php think wise:notification:retry-failed
 *   php think wise:notification:retry-failed --channel=email
 *   php think wise:notification:retry-failed --date=2026-06-23
 *   php think wise:notification:retry-failed --max=50
 */
class RetryFailed extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:notification:retry-failed')
            ->setDescription('重试失败的通知（从历史表中读取失败记录重新投递）')
            ->addOption('channel', 'c', \think\console\input\Option::VALUE_OPTIONAL, '指定通道', null)
            ->addOption('date', 'd', \think\console\input\Option::VALUE_OPTIONAL, '指定日期（Y-m-d）', null)
            ->addOption('max', 'm', \think\console\input\Option::VALUE_OPTIONAL, '最大重试数量', 100)
            ->addOption('dead', null, \think\console\input\Option::VALUE_NONE, '包含死信记录');
    }

    protected function execute(Input $input, Output $output): int
    {
        $channel = $input->getOption('channel');
        $date = $input->getOption('date');
        $max = (int) $input->getOption('max');
        $includeDead = $input->getOption('dead');

        $output->writeln('<info>=== 失败通知重试 ===</info>');
        $output->writeln("通道: " . ($channel ?? '全部'));
        $output->writeln("日期: " . ($date ?? '全部'));
        $output->writeln("最大数量: {$max}");

        // 构建查询
        $query = NotificationHistory::where('status', 'in', $includeDead ? ['failed', 'dead'] : ['failed']);

        if ($channel !== null) {
            $query->where('channel', '=', $channel);
        }
        if ($date !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $output->error("日期格式无效: {$date}，请使用 Y-m-d 格式");
                return 1;
            }
            $query->whereBetween('create_time', [$date . ' 00:00:00', $date . ' 23:59:59']);
        }

        $records = $query->limit($max)->order('id', 'asc')->select();
        $count = count($records);
        $retried = 0;
        $skipped = 0;

        $output->writeln("找到 {$count} 条失败记录\n");

        $queueName = config('wise-notification.queue.queue', 'notification');
        try {
            $queue = app()->make('queue');
        } catch (\Throwable $e) {
            $output->error("队列服务不可用: {$e->getMessage()}");
            return 1;
        }

        foreach ($records as $record) {
            try {
                $payload = json_decode($record->message_payload, true);
                if (!is_array($payload)) {
                    $output->writeln("<comment>跳过 #{$record->id}: message_payload 无效</comment>");
                    $skipped++;
                    continue;
                }

                $jobData = [
                    'channel'         => $record->channel,
                    'notifiable_type' => $record->notifiable_type,
                    'notifiable_id'   => $record->notifiable_id,
                    'message'         => $payload,
                    'message_type'    => $record->message_type,
                    'is_batch'        => false,
                    'batch_id'        => null,
                    'history_id'      => $record->id,
                    'retry'           => [
                        'attempt'      => 0,
                        'max_attempts' => config('wise-notification.retry.max_attempts', 3),
                        'backoff'      => config('wise-notification.retry.backoff', [1, 5, 15]),
                    ],
                ];

                // 更新原记录状态为 pending
                $record->save(['status' => 'pending', 'error' => null]);

                // 重新推入队列
                $queue->push(\wise\notification\job\SendNotificationJob::class, $jobData, $queueName);

                $output->writeln("<info>已重试 #{$record->id} ({$record->channel} -> {$record->notifiable_type})</info>");
                $retried++;

            } catch (\Throwable $e) {
                $output->writeln("<error>重试失败 #{$record->id}: {$e->getMessage()}</error>");
                $skipped++;
            }
        }

        $output->writeln("\n<info>完成: 重试 {$retried} 条，跳过 {$skipped} 条</info>");

        return 0;
    }
}
