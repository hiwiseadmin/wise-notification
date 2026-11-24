<?php

declare(strict_types=1);

namespace wise\notification\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\notification\health\ChannelHealthCheck;
use wise\notification\quota\QuotaManager;

/**
 * HealthCheck — 通道健康检查命令
 *
 * 检查所有通知通道的连通性并输出报告，并附带当日配额使用情况。
 *
 * 用法：
 *   php think wise:notification:health
 *   php think wise:notification:health --channel=email
 */
class HealthCheck extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:notification:health')
            ->setDescription('检查通知通道连通性')
            ->addOption('channel', 'c', \think\console\input\Option::VALUE_OPTIONAL, '指定通道（不指定则检查全部）', null);
    }

    protected function execute(Input $input, Output $output): int
    {
        $channel = $input->getOption('channel');
        $checker = new ChannelHealthCheck();

        if ($channel !== null) {
            $result = $checker->check($channel);
            $icon = $result['healthy'] ? '<info>[OK]</info>' : '<error>[FAIL]</error>';
            $output->writeln("{$icon} {$channel} ({$result['latency_ms']}ms)");
            if (!empty($result['error'])) {
                $output->writeln("<error>  错误: {$result['error']}</error>");
            }
        } else {
            $output->writeln($checker->getReport());
            $this->outputQuotaStats($output);
        }

        return 0;
    }

    /**
     * 输出当日配额使用情况
     *
     * 配额消费在发送链路完成，这里通过 QuotaManager 只读查询同一套计数键
     * （缓存不可用时仅提示，不影响健康检查结论）。
     */
    private function outputQuotaStats(Output $output): void
    {
        $output->writeln('');
        $output->writeln('<info>=== 当日配额使用（用户/通道日配额，0=不限制） ===</info>');

        try {
            foreach ((new QuotaManager())->getDailyStats() as $channel => $stat) {
                $limitText = $stat['limit'] > 0
                    ? "{$stat['used']}/{$stat['limit']} ({$stat['usage_pct']}%)" . ($stat['exhausted'] ? ' [已用尽]' : '')
                    : '不限制';
                $output->writeln("  {$channel}: {$limitText}");
            }
        } catch (\Throwable $e) {
            $output->writeln('<comment>配额统计不可用（缓存通道不可用）: ' . $e->getMessage() . '</comment>');
        }
    }
}
