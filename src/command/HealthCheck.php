<?php

declare(strict_types=1);

namespace wise\notification\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\notification\health\ChannelHealthCheck;

/**
 * HealthCheck — 通道健康检查命令
 *
 * 检查所有通知通道的连通性并输出报告。
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
        }

        return 0;
    }
}
