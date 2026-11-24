<?php

declare(strict_types=1);

namespace wise\notification\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\facade\Notification;
use wise\notification\traits\NotifiableTrait;

/**
 * 测试发送命令：wise:notification:send
 *
 * 用于从命令行快速测试通知发送，不依赖实际 Notifiable 模型。
 *
 * 用法：
 *   php think wise:notification:send --channel=email --to=admin@example.com --title="Test" --content="Hello"
 *   php think wise:notification:send --channel=email --to=admin@example.com --title="Test" --content="Hello" --sync
 */
class Send extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:notification:send')
            ->setDescription('测试发送通知')
            ->addOption('channel', 'c', \think\console\input\Option::VALUE_REQUIRED, '发送通道', 'email')
            ->addOption('to', 't', \think\console\input\Option::VALUE_REQUIRED, '接收地址（邮箱/手机号/用户ID）')
            ->addOption('title', null, \think\console\input\Option::VALUE_REQUIRED, '消息标题')
            ->addOption('content', null, \think\console\input\Option::VALUE_REQUIRED, '消息内容')
            ->addOption('sync', 's', \think\console\input\Option::VALUE_NONE, '同步发送（跳过队列）');
    }

    /**
     * 执行命令
     *
     * @param Input  $input  输入
     * @param Output $output 输出
     * @return int 状态码
     */
    protected function execute(Input $input, Output $output): int
    {
        $channel = $input->getOption('channel') ?: 'email';
        $to      = $input->getOption('to');
        $title   = $input->getOption('title');
        $content = $input->getOption('content');
        $sync    = $input->getOption('sync');

        if (empty($to)) {
            $output->error('缺少必需参数 --to（接收地址）');
            return 1;
        }

        if (empty($title)) {
            $output->error('缺少必需参数 --title（消息标题）');
            return 1;
        }

        if (empty($content)) {
            $output->error('缺少必需参数 --content（消息内容）');
            return 1;
        }

        $notifiable = new class($to) implements Notifiable {
            use NotifiableTrait;

            public function __construct(private readonly string $target) {}

            public function routeNotificationFor(string $channel): string|array
            {
                return $this->target;
            }

            public function getNotifiableId(): string
            {
                return $this->target;
            }

            public function getNotifiableType(): string
            {
                return 'console';
            }
        };

        $message = new Message(
            title: $title,
            content: $content,
        );

        $output->writeln("<info>通道：{$channel}</info>");
        $output->writeln("<info>接收：{$to}</info>");
        $output->writeln("<info>模式：" . ($sync ? '同步' : '异步') . "</info>");
        $output->writeln('');

        $manager = Notification::channel($channel);

        if ($sync) {
            $manager->sync();
        }

        $result = $manager->send($notifiable, $message);

        if ($result === null) {
            $output->info('通知已推送到队列，等待 Worker 消费...');
            return 0;
        }

        if ($result instanceof \wise\notification\contract\BatchSendResult) {
            $output->writeln("<info>批量发送完成：总数 {$result->total}，成功 {$result->successCount}，失败 {$result->failedCount}</info>");
            foreach ($result->results as $sr) {
                $this->outputResult($output, $sr);
            }

            return $result->failedCount > 0 ? 1 : 0;
        }

        $this->outputResult($output, $result);

        return $result->success ? 0 : 1;
    }

    /**
     * 输出单个发送结果
     *
     * @param Output                                   $output
     * @param \wise\notification\contract\SendResult  $result
     */
    private function outputResult(Output $output, \wise\notification\contract\SendResult $result): void
    {
        if ($result->success) {
            $output->writeln("<info>[✓] {$result->channel} — 成功 （{$result->durationMs}ms）</info>");
            if ($result->response) {
                $output->writeln("  响应：{$result->response}");
            }
        } else {
            $output->writeln("<error>[✗] {$result->channel} — 失败 （{$result->durationMs}ms）</error>");
            if ($result->error) {
                $output->writeln("  错误：{$result->error}");
            }
        }
    }
}
