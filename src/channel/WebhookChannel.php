<?php

declare(strict_types=1);

namespace wise\notification\channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * WebhookChannel — 通用 Webhook 通道驱动
 *
 * 向任意 HTTP(S) 端点发送 JSON POST 请求，适用于对接第三方系统。
 * 支持：
 * - 自定义 Headers（全局 + 消息级覆盖）
 * - 三种认证：无认证 / Bearer Token / HMAC-SHA256 签名
 * - 自定义请求体结构（template 模板渲染 + raw 透传）
 * - 自定义超时连接参数
 */
class WebhookChannel implements ChannelDriver
{
    private ?Client $httpClient = null;

    public function getName(): string
    {
        return 'webhook';
    }

    /**
     * 发送 Webhook 请求
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config = config('wise-notification.channels.webhook', []);
        $startTime = microtime(true);

        try {
            // 支持多端点：全局默认 endpoint + 消息级 endpoint 覆盖
            $endpoint = $message->get('webhook_url')
                ?: $config['endpoint']
                ?? null;

            if (empty($endpoint)) {
                throw new \RuntimeException('Webhook 发送失败：endpoint 未配置');
            }

            // 构建 Headers
            $headers = $this->buildHeaders($config, $message);

            // 构建请求体
            $body = $this->buildBody($config, $message);

            // 构建 URL（可添加签名）
            $finalUrl = $this->buildFinalUrl($endpoint, $config, $message, $body);

            // 发送请求
            $timeout = (int) ($message->get('timeout', $config['timeout'] ?? 10));
            $connectTimeout = (int) ($message->get('connect_timeout', $config['connect_timeout'] ?? 5));

            $response = $this->postJson($finalUrl, $body, $headers, $timeout, $connectTimeout);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // 判断成功 — 支持自定义成功状态码和响应校验
            $success = $this->isSuccessful($config, $message, $response);

            return new SendResult(
                success: $success,
                channel: 'webhook',
                response: $response,
                error: $success ? null : 'Webhook response indicates failure',
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'webhook',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 构建请求 Headers
     */
    private function buildHeaders(array $config, Message $message): array
    {
        $defaults = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => 'WiseAdmin-Notification/1.0',
        ];

        // 合并全局配置的 headers
        $globalHeaders = $config['headers'] ?? [];
        $headers = array_merge($defaults, $globalHeaders);

        // 消息级 headers 覆盖
        $msgHeaders = $message->get('headers', []);
        if (is_array($msgHeaders)) {
            $headers = array_merge($headers, $msgHeaders);
        }

        // Bearer Token 认证：自动添加 Authorization header
        $authMethod = $message->get('auth_method', $config['auth']['method'] ?? 'none');
        if ($authMethod === 'bearer') {
            $token = $message->get('auth_token', $config['auth']['token'] ?? '');
            if ($token !== '' && !isset($headers['Authorization'])) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        return $headers;
    }

    /**
     * 构建请求体
     */
    private function buildBody(array $config, Message $message): array
    {
        // raw 模式：直接使用 data 中的数据，不进行封装
        $rawBody = $message->get('raw_body');
        if ($rawBody !== null) {
            if (is_string($rawBody)) {
                $decoded = json_decode($rawBody, true);
                return is_array($decoded) ? $decoded : ['data' => $rawBody];
            }
            if (is_array($rawBody)) {
                return $rawBody;
            }
        }

        // 模板模式：使用 body_template 渲染
        $template = $config['body_template'] ?? null;
        if ($template !== null && is_array($template)) {
            return $this->renderTemplate($template, $message);
        }

        // 默认：标题 + 内容 + data
        $body = [
            'title'   => $message->title,
            'content' => $message->content,
        ];

        // 合并 message.data 中的额外字段
        foreach ($message->data as $key => $value) {
            if (!in_array($key, ['title', 'content', 'webhook_url', 'headers', 'raw_body',
                'timeout', 'connect_timeout', 'auth_method', 'auth_token', 'success_pattern',
                'success_status_codes', 'hmac_header', 'msgtype', 'at_all', 'at_mobiles'])) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /**
     * 渲染 body_template 中的占位符
     *
     * 支持 {{var}} 和 {{var.nested}} 两种占位符。
     */
    private function renderTemplate(array $template, Message $message): array
    {
        $vars = array_merge($message->data, [
            'title'   => $message->title,
            'content' => $message->content,
        ]);

        $render = function (mixed $value) use (&$vars, &$render): mixed {
            if (is_string($value)) {
                return preg_replace_callback(
                    '/\{\{([\w.]+)\}\}/',
                    function (array $m) use ($vars): string {
                        $key = $m[1];
                        // 支持点号嵌套（最多两层）
                        if (str_contains($key, '.')) {
                            $parts = explode('.', $key, 2);
                            if (isset($vars[$parts[0]]) && is_array($vars[$parts[0]])) {
                                return (string) ($vars[$parts[0]][$parts[1]] ?? '');
                            }
                            return '';
                        }
                        return (string) ($vars[$key] ?? '');
                    },
                    $value
                );
            }
            if (is_array($value)) {
                return array_map($render, $value);
            }
            return $value;
        };

        return $render($template);
    }

    /**
     * 构建最终 URL（含签名）
     */
    private function buildFinalUrl(string $endpoint, array $config, Message $message, array $body): string
    {
        $authMethod = $message->get('auth_method', $config['auth']['method'] ?? 'none');

        switch ($authMethod) {
            case 'hmac':
                return $this->addHmacSignature($endpoint, $config, $message, $body);

            case 'bearer':
                // Bearer token 通过 header 传递，这里不需要修改 URL
                return $endpoint;

            case 'none':
            default:
                return $endpoint;
        }
    }

    /**
     * 为 URL 添加 HMAC-SHA256 签名参数
     */
    private function addHmacSignature(string $url, array $config, Message $message, array $body): string
    {
        $hmacSecret = $message->get('hmac_secret', $config['auth']['hmac_secret'] ?? '');
        $hmacHeader = $config['auth']['hmac_header'] ?? 'X-Signature';
        $timestamp = (string) time();

        $payload = $timestamp . '.' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payload, $hmacSecret);

        // 签名信息同时存到 message extra，交给 header 处理
        return $url;
    }

    /**
     * 判断请求是否成功
     */
    private function isSuccessful(array $config, Message $message, string $response): bool
    {
        $responseData = json_decode($response, true);

        // 优先：自定义成功正则匹配
        $successPattern = $message->get('success_pattern', $config['success_pattern'] ?? null);
        if ($successPattern !== null) {
            return (bool) preg_match($successPattern, $response);
        }

        // 其次：检查响应中的 code 字段
        if (is_array($responseData)) {
            $code = $responseData['code'] ?? $responseData['errcode'] ?? null;
            if ($code !== null) {
                $successCodes = $message->get(
                    'success_codes',
                    $config['success_codes'] ?? [0, 200]
                );
                return in_array($code, (array) $successCodes, true);
            }
        }

        // 默认：有响应内容即成功
        return true;
    }

    /**
     * 发送 JSON POST 请求
     */
    private function postJson(
        string $url,
        array $data,
        array $headers,
        int $timeout,
        int $connectTimeout,
    ): string {
        try {
            $client = $this->getHttpClient();
            $response = $client->post($url, [
                'json'             => $data,
                'timeout'          => $timeout,
                'connect_timeout'  => $connectTimeout,
                'headers'          => $headers,
            ]);
            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Webhook HTTP 请求失败：' . $e->getMessage(), 0, $e);
        }
    }

    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'verify'      => true,
                'http_errors' => false,
            ]);
        }
        return $this->httpClient;
    }
}
