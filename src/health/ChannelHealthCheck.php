<?php

declare(strict_types=1);

namespace wise\notification\health;

use GuzzleHttp\Client;
use wise\notification\NotificationManager;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;

/**
 * ChannelHealthCheck — 通道连通性健康检查
 *
 * 心跳检测各通道是否可用，支持：
 * - SMTP 连接测试
 * - HTTP API 连通性测试（钉钉/飞书/Webhook）
 * - 企业微信 access_token 获取测试
 * - 微信公众号 access_token 获取测试
 */
class ChannelHealthCheck
{
    /** @var array<string, bool> 检查结果缓存 */
    private static array $results = [];

    /**
     * 检查所有通道健康状态
     *
     * @return array<string, array{healthy: bool, latency_ms: int, error: string}>
     */
    public function checkAll(): array
    {
        $channels = ['email', 'sms', 'dingtalk', 'wecom', 'feishu', 'webhook', 'wechat_oa'];
        $results = [];

        foreach ($channels as $channel) {
            $results[$channel] = $this->check($channel);
        }

        self::$results = $results;
        return $results;
    }

    /**
     * 检查单个通道
     */
    public function check(string $channel): array
    {
        $config = config("wise-notification.channels.{$channel}", []);
        $startTime = microtime(true);

        try {
            $healthy = match ($channel) {
                'email'    => $this->checkEmail($config),
                'sms'      => $this->checkSms($config),
                'dingtalk' => $this->checkHttpEndpoint(
                    $config['webhook_url'] ?? '',
                    'dingtalk'
                ),
                'wecom'    => $this->checkWecom($config),
                'feishu'   => $this->checkHttpEndpoint(
                    $config['webhook_url'] ?? '',
                    'feishu'
                ),
                'webhook'  => $this->checkHttpEndpoint(
                    $config['endpoint'] ?? '',
                    'webhook'
                ),
                'wechat_oa' => $this->checkWechatOa($config),
                'database'  => true,
                default    => $this->checkCustomChannel($channel),
            };

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'healthy'    => $healthy,
                'latency_ms' => $latencyMs,
                'error'      => $healthy ? '' : 'Channel health check failed',
            ];
        } catch (\Throwable $e) {
            return [
                'healthy'    => false,
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'error'      => $e->getMessage(),
            ];
        }
    }

    /**
     * 检查所有不健康的通道
     *
     * @return array<string, array>
     */
    public function getUnhealthyChannels(): array
    {
        $all = $this->checkAll();
        return array_filter($all, fn (array $r) => !$r['healthy']);
    }

    /**
     * 格式化健康检查报告
     */
    public function getReport(): string
    {
        $all = $this->checkAll();
        $lines = [];
        $lines[] = '=== 通知通道健康检查报告 ===';
        $lines[] = '检查时间: ' . date('Y-m-d H:i:s');
        $lines[] = '';

        $unhealthyCount = 0;
        foreach ($all as $channel => $result) {
            $icon = $result['healthy'] ? '[OK]' : '[FAIL]';
            $latency = $result['latency_ms'];
            $error = $result['error'] ? " — {$result['error']}" : '';
            $lines[] = "{$icon} {$channel} ({$latency}ms){$error}";

            if (!$result['healthy']) {
                $unhealthyCount++;
            }
        }

        $total = count($all);
        $lines[] = '';
        $lines[] = "结果: {$total} 个通道，{$unhealthyCount} 个异常";

        return implode("\n", $lines);
    }

    // ==================== 通道检查方法 ====================

    private function checkEmail(array $config): bool
    {
        $host = $config['host'] ?? '';
        $port = (int) ($config['port'] ?? 587);

        if (empty($host)) return true; // 未配置视为不需要检查

        try {
            $timeout = 5;
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen(
                ($config['encryption'] ?? 'tls') === 'ssl' ? "ssl://{$host}" : $host,
                $port,
                $errno,
                $errstr,
                $timeout,
            );
            if ($socket) {
                fclose($socket);
                return true;
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkSms(array $config): bool
    {
        $provider = $config['provider'] ?? 'aliyun';
        return in_array($provider, ['aliyun', 'tencent', 'none'], true);
    }

    private function checkHttpEndpoint(string $url, string $label): bool
    {
        if (empty($url)) return true;

        try {
            $client = new Client([
                'verify'      => false,
                'http_errors' => false,
            ]);

            $response = $client->get($url, [
                'timeout'         => 10,
                'connect_timeout' => 5,
                'headers'         => ['User-Agent' => 'WiseAdmin-HealthCheck/1.0'],
            ]);
            $statusCode = $response->getStatusCode();

            return $statusCode < 500;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkWecom(array $config): bool
    {
        $corpId = $config['corp_id'] ?? '';
        $secret = $config['secret'] ?? '';

        if (empty($corpId) || empty($secret)) return true;

        try {
            $client = new Client(['verify' => true, 'http_errors' => false]);
            $response = $client->get(
                "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid={$corpId}&corpsecret={$secret}",
                ['timeout' => 10, 'connect_timeout' => 5],
            );
            $data = json_decode((string) $response->getBody(), true);
            return ($data['errcode'] ?? -1) === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkWechatOa(array $config): bool
    {
        $appId = $config['app_id'] ?? '';
        $appSecret = $config['app_secret'] ?? '';

        if (empty($appId) || empty($appSecret)) return true;

        try {
            $client = new Client(['verify' => true, 'http_errors' => false]);
            $response = $client->get(
                "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appId}&secret={$appSecret}",
                ['timeout' => 10, 'connect_timeout' => 5],
            );
            $data = json_decode((string) $response->getBody(), true);
            return isset($data['access_token']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkCustomChannel(string $channel): bool
    {
        try {
            $manager = app()->make(NotificationManager::class);
            $driver = $manager->resolveDriver($channel);
            return $driver !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
