<?php

declare(strict_types=1);

namespace wise\notification\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\notification\model\NotificationHistory;

/**
 * CleanHistory — 发送历史清理命令
 *
 * 按 config('wise-notification.history_retention_days') 删除过期历史，
 * 避免历史数据（含消息载荷快照）无限堆积。0 表示永久保留，命令直接跳过。
 *
 * 用法：
 *   php think wise:notification:clean-history
 *   php think wise:notification:clean-history --days=30
 *   php think wise:notification:clean-history --dead-only
 *
 * 建议通过 wise-crontab 定时执行（如每天凌晨 03:30）。
 */
class CleanHistory extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:notification:clean-history')
            ->setDescription('清理过期的通知发送历史（默认按 history_retention_days）')
            ->addOption('days', 'd', \think\console\input\Option::VALUE_OPTIONAL, '保留天数（覆盖配置）', null)
            ->addOption('dead-only', null, \think\console\input\Option::VALUE_NONE, '仅清理死信记录');
    }

    protected function execute(Input $input, Output $output): int
    {
        $daysOption = $input->getOption('days');
        $days = $daysOption === null
            ? (int) config('wise-notification.history_retention_days', 90)
            : (int) $daysOption;

        if ($days <= 0) {
            $output->writeln('<comment>history_retention_days <= 0（永久保留），无需清理。</comment>');
            return 0;
        }

        $deadline = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deadOnly = (bool) $input->getOption('dead-only');

        $output->writeln('<info>=== 通知发送历史清理 ===</info>');
        $output->writeln("保留天数: {$days}（删除 create_time < {$deadline} 的记录）");
        $output->writeln('范围: ' . ($deadOnly ? '仅死信（status=dead）' : '全部状态'));

        try {
            $query = NotificationHistory::where('create_time', '<', $deadline);
            if ($deadOnly) {
                $query->where('status', '=', 'dead');
            }

            $deleted = $query->delete();
        } catch (\Throwable $e) {
            $output->error('清理失败: ' . $e->getMessage());
            return 1;
        }

        $output->writeln("\n<info>完成：已清理 {$deleted} 条历史记录</info>");

        return 0;
    }
}
