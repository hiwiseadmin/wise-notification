<?php

declare(strict_types=1);

namespace wise\notification\channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * DingtalkChannel — 钉钉机器人通道驱动
 *
 * 通过钉钉自定义机器人 Webhook 发送 Markdown 类型消息。
 * 使用 HMAC-SHA256 签名认证，HTTP 客户端使用 GuzzleHttp\Client（连接复用）。
 */
class DingtalkChannel implements ChannelDriver
{
    /**
     * GuzzleHttp 客户端实例（连接复用）
     *
     * @var Client|null
     */
    private ?Client $httpClient = null;

    /**
     * 获取通道名称
     *
     * @return string
     */
    public function getName(): string
    {
        return 'dingtalk';
    }

    /**
     * 发送钉钉机器人消息
     *
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @return SendResult
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config = config('wise-notification.channels.dingtalk', []);
        $webhookUrl = $config['webhook_url'] ?? '';
        $secret = $config['secret'] ?? '';
        $startTime = microtime(true);

        try {
            if (empty($webhookUrl)) {
                throw new \RuntimeException('钉钉发送失败：webhook_url 未配置');
            }

            // 构造签名 URL
            $finalUrl = $webhookUrl;
            if (!empty($secret)) {
                $finalUrl = $this->buildSignedUrl($webhookUrl, $secret);
            }

            // 构造 Markdown 消息体
            $body = [
                'msgtype'  => 'markdown',
                'markdown' => [
                    'title' => $message->title,
                    'text'  => $message->content,
                ],
            ];

            // 如果有 atMobiles，追加到消息体
            $atMobiles = $message->get('at_mobiles');
            if ($atMobiles !== null && is_array($atMobiles)) {
                $body['markdown']['text'] .= "\n\n" . implode(
                    ' ',
                    array_map(fn (string $m) => '@' . $m, $atMobiles)
                );
                $body['at'] = [
                    'atMobiles' => $atMobiles,
                    'isAtAll'   => false,
                ];
            }

            if ($message->get('at_all') === true) {
                $body['at'] = ['isAtAll' => true];
            }

            // 发送 HTTP POST 请求（GuzzleHttp，连接复用）
            $response = $this->postJson($finalUrl, $body);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $responseData = json_decode($response, true);
            $errcode = $responseData['errcode'] ?? -1;

            if ($errcode === 0) {
                return new SendResult(
                    success: true,
                    channel: 'dingtalk',
                    response: $response,
                    durationMs: $durationMs,
                );
            }

            return new SendResult(
                success: false,
                channel: 'dingtalk',
                response: $response,
                error: $responseData['errmsg'] ?? 'Unknown error',
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'dingtalk',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 构造带签名的 URL
     *
     * 签名算法：timestamp + "\n" + secret → HMAC-SHA256 → base64 → URL encode
     *
     * @param string $webhookUrl 原始 Webhook URL
     * @param string $secret     密钥
     * @return string 带签名参数的 URL
     */
    private function buildSignedUrl(string $webhookUrl, string $secret): string
    {
        $timestamp = (string) (time() * 1000);
        $stringToSign = $timestamp . "\n" . $secret;
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));
        $encodedSign = urlencode($signature);

        $separator = (str_contains($webhookUrl, '?')) ? '&' : '?';
        return $webhookUrl . $separator . 'timestamp=' . $timestamp . '&sign=' . $encodedSign;
    }

    /**
     * 发送 JSON POST 请求（使用 GuzzleHttp，支持连接复用和 SSL 验证）
     *
     * @param string $url  请求 URL
     * @param array  $data 请求体
     * @return string 响应正文
     * @throws \RuntimeException 当 HTTP 请求失败时
     */
    private function postJson(string $url, array $data): string
    {
        try {
            $client = $this->getHttpClient();

            $response = $client->post($url, [
                'json'    => $data,
                'timeout' => 10,
                'connect_timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
            ]);

            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('钉钉 HTTP 请求失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取或创建 GuzzleHttp 客户端实例（懒加载，连接复用）
     *
     * @return Client
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'verify'  => true, // 启用 SSL 证书验证
                'http_errors' => false, // 不抛出 4xx/5xx 异常，由调用方自行处理
            ]);
        }

        return $this->httpClient;
    }
}
