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
 *
 * 安全约束（SSRF 防护）：
 * - 端点默认仅取配置项 `endpoint`；消息级 `webhook_url` 覆盖需显式开启
 *   `allow_message_endpoint`，开启后目标 URL 会经过协议白名单 + 内网/保留地址拦截；
 * - 消息级 `headers` 不允许覆盖 Authorization / Cookie / Host 等敏感头；
 * - HTTP 客户端不自动跟随重定向，避免 3xx 绕过端点校验。
 */
class WebhookChannel implements \wise\notification\connector\NotificationConnectorInterface
{
    use \wise\notification\connector\NotificationConnectorTrait;

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
            // 端点来源：默认只信配置文件；消息级 webhook_url 覆盖默认禁用（SSRF 防护）。
            // 消息载荷常来自业务数据（可能被用户输入污染），一旦允许覆盖，
            // 即可让服务端向任意地址（含内网）发起携带凭据的 POST。
            $messageEndpoint = $message->get('webhook_url');
            $messageEndpoint = is_string($messageEndpoint) ? trim($messageEndpoint) : '';
            $allowOverride   = (bool) ($config['allow_message_endpoint'] ?? false);

            $resolve = [];
            if ($messageEndpoint !== '') {
                if (!$allowOverride) {
                    throw new \RuntimeException(
                        'Webhook 发送失败：已禁用消息级 webhook_url 覆盖（如需启用请设置 '
                        . 'wise-notification.channels.webhook.allow_message_endpoint=true）'
                    );
                }
                // 返回 CURLOPT_RESOLVE 条目：把「校验时解析出的 IP」固定给 curl，
                // 消除「先校验 DNS、再由 HTTP 客户端重新解析」的 TOCTOU / DNS rebinding 窗口
                $resolve = $this->assertEndpointAllowed($messageEndpoint);
                $endpoint = $messageEndpoint;
            } else {
                $endpoint = $config['endpoint'] ?? null;
            }

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

            $response = $this->postJson($finalUrl, $body, $headers, $timeout, $connectTimeout, $resolve);
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

        // 消息级 headers 覆盖：过滤可劫持认证 / 路由的敏感头
        $msgHeaders = $message->get('headers', []);
        if (is_array($msgHeaders)) {
            foreach ($msgHeaders as $name => $value) {
                if (!is_string($name) || $this->isForbiddenHeader($name)) {
                    continue;
                }
                $headers[$name] = $value;
            }
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
                'success_status_codes', 'hmac_secret', 'hmac_header', 'msgtype', 'at_all',
                'at_mobiles', '_priority'], true)) {
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

        // 将签名拼接到 URL 查询参数中
        $url .= (str_contains($url, '?') ? '&' : '?')
            . $hmacHeader . '=' . urlencode($signature)
            . '&timestamp=' . $timestamp;

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
            // 校验正则合法性，避免非法正则被 preg_match 误判为失败
            if (@preg_match($successPattern, '') === false) {
                $successPattern = null;
            } else {
                return (bool) preg_match($successPattern, $response);
            }
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

        // 无可识别成功标识时：仅当响应非空且为合法 JSON 才视为成功，避免空错误页误判
        return $response !== '' && $responseData !== null;
    }

    /**
     * 发送 JSON POST 请求
     */
    /**
     * @param array $resolve CURLOPT_RESOLVE 条目（host:port:ip），由 assertEndpointAllowed() 产出
     */
    private function postJson(string $url, array $data, array $headers, int $timeout, int $connectTimeout, array $resolve = []): string {
        try {
            $options = [
                'json'             => $data,
                'timeout'          => $timeout,
                'connect_timeout'  => $connectTimeout,
                'headers'          => $headers,
            ];
            if ($resolve !== []) {
                // 复用校验阶段解析出的 IP，杜绝二次解析带来的 DNS rebinding 绕过
                $options['curl'] = [CURLOPT_RESOLVE => $resolve];
            }

            $client = $this->getHttpClient();
            $response = $client->post($url, $options);
            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Webhook HTTP 请求失败：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 出站端点安全校验（SSRF 防护）
     *
     * 仅在允许消息级端点覆盖时调用：校验协议白名单，
     * 并确保主机解析出的所有 IP（A + AAAA）均非内网/保留地址。
     * 复用 PHP 内置过滤器 FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE。
     *
     * 无法解析出任何 IP 时一律拒绝（fail-closed）：否则十进制/简写 IP
     * （如 http://2130706433/）或 DNS 解析失败都会绕过内网校验。
     *
     * 返回值用于把校验通过的 IP 固定给 curl（CURLOPT_RESOLVE），
     * 避免「校验用一次 DNS、真正请求再用一次 DNS」的 TOCTOU / DNS rebinding 窗口。
     *
     * @param string $url 待校验 URL
     * @return string[] CURLOPT_RESOLVE 条目（host:port:ip）
     * @throws \RuntimeException 协议非法、主机无法解析或命中内网/保留地址
     */
    private function assertEndpointAllowed(string $url): array
    {
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        // IPv6 字面量在 parse_url 结果中带方括号（如 [::1]），需剥离后再校验
        $host   = trim((string) ($parsed['host'] ?? ''), '[]');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Webhook 端点非法：仅允许 http/https 且必须包含主机名');
        }

        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new \RuntimeException("Webhook 端点被拒绝：无法解析主机地址（{$host}）");
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException("Webhook 端点被拒绝：不允许指向内网/保留地址（{$host}）");
            }
        }

        return array_map(static fn (string $ip): string => "{$host}:{$port}:{$ip}", $ips);
    }

    /**
     * 解析主机名的全部 IP（IPv4 A + IPv6 AAAA）
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_filter(
            array_unique($ips),
            static fn (mixed $ip): bool => is_string($ip) && (bool) filter_var($ip, FILTER_VALIDATE_IP)
        ));
    }

    /**
     * 禁止由消息载荷覆盖的请求头（防止凭据 / 路由劫持）
     */
    private function isForbiddenHeader(string $name): bool
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return true;
        }

        $forbidden = [
            'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
            'host', 'content-length', 'connection', 'transfer-encoding',
            'x-real-ip',
        ];

        if (in_array($name, $forbidden, true)) {
            return true;
        }

        // X-Forwarded-* 一律禁止由消息覆盖（影响上游路由判定）
        return str_starts_with($name, 'x-forwarded-');
    }

    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'verify'          => true,
                'http_errors'     => false,
                // 禁止自动跟随重定向：3xx 可能把请求引导到内网地址，绕过端点校验
                'allow_redirects' => false,
            ]);
        }
        return $this->httpClient;
    }
}
