<?php

declare(strict_types=1);

namespace wise\notification\channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * WechatOfficialAccountChannel — 微信公众号模板消息通道驱动
 *
 * 通过微信公众号模板消息 API 发送通知，适合 C 端用户触达。
 * 使用 appid + secret 获取 access_token，access_token 自动缓存。
 * 需要微信服务号并已申请模板消息权限。
 *
 * API 文档：https://developers.weixin.qq.com/doc/offiaccount/Message_Management/Template_Message.html
 */
class WechatOfficialAccountChannel implements ChannelDriver
{
    private ?Client $httpClient = null;

    /** access_token 内存缓存 */
    private static array $tokenCache = [];

    public function getName(): string
    {
        return 'wechat_oa';
    }

    /**
     * 发送微信公众号模板消息
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config = config('wise-notification.channels.wechat_oa', []);
        $appId = $config['app_id'] ?? '';
        $appSecret = $config['app_secret'] ?? '';
        $startTime = microtime(true);

        try {
            if (empty($appId) || empty($appSecret)) {
                throw new \RuntimeException('微信公众号发送失败：app_id/app_secret 未配置');
            }

            // 获取接收者 OpenID
            $toUser = $notifiable->routeNotificationFor('wechat_oa');
            if (is_array($toUser)) {
                $toUser = (string) ($toUser[0] ?? '');
            }
            if (empty($toUser)) {
                throw new \RuntimeException('微信公众号发送失败：接收者 OpenID 为空');
            }

            // 获取 access_token
            $accessToken = $this->getAccessToken($appId, $appSecret);

            // 确定模板ID：优先从 message 中获取，其次从配置 template_map 获取
            $templateId = $message->get('template_id')
                ?: $config['default_template_id']
                ?? null;

            if (empty($templateId)) {
                throw new \RuntimeException('微信公众号发送失败：template_id 未指定');
            }

            // 构造跳转链接（可选）
            $url = $message->get('url');
            $miniprogram = $message->get('miniprogram');

            // 构造模板数据
            $templateData = $message->get('template_data', []);
            if (empty($templateData)) {
                // 从 message 自动生成基础模板数据
                $templateData = [
                    'first'    => ['value' => $message->title, 'color' => '#173177'],
                    'keyword1' => ['value' => $message->content, 'color' => '#173177'],
                ];

                // 合并 data 中的自定义字段为 keyword2/3/4...
                $extraFields = array_diff_key(
                    $message->data,
                    array_flip(['title', 'content', 'template_id', 'url', 'miniprogram',
                        'template_data', 'msgtype', 'at_all', 'at_mobiles'])
                );
                $idx = 2;
                foreach ($extraFields as $value) {
                    $templateData["keyword{$idx}"] = [
                        'value' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
                        'color' => '#173177',
                    ];
                    $idx++;
                }
            }

            // 构造请求体
            $body = [
                'touser'      => $toUser,
                'template_id' => $templateId,
                'data'        => $this->formatTemplateData($templateData),
            ];

            if ($url !== null) {
                $body['url'] = $url;
            }

            if ($miniprogram !== null && is_array($miniprogram)) {
                $body['miniprogram'] = [
                    'appid'    => $miniprogram['appid'] ?? '',
                    'pagepath' => $miniprogram['pagepath'] ?? '',
                ];
            }

            // 发送请求
            $response = $this->postJson(
                "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token={$accessToken}",
                $body
            );
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $responseData = json_decode($response, true);
            $errcode = $responseData['errcode'] ?? -1;

            if ($errcode === 0) {
                return new SendResult(
                    success: true,
                    channel: 'wechat_oa',
                    response: $response,
                    durationMs: $durationMs,
                );
            }

            return new SendResult(
                success: false,
                channel: 'wechat_oa',
                response: $response,
                error: $responseData['errmsg'] ?? 'Unknown error',
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'wechat_oa',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 获取微信公众号 access_token
     *
     * 使用内存缓存，有效期 7200 秒（提前 300 秒刷新）。
     */
    private function getAccessToken(string $appId, string $appSecret): string
    {
        $cacheKey = "wechat_oa_token_{$appId}";

        if (isset(self::$tokenCache[$cacheKey])) {
            $cached = self::$tokenCache[$cacheKey];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
        }

        $response = $this->getJson(
            "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appId}&secret={$appSecret}"
        );
        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            throw new \RuntimeException(
                '微信公众号获取 access_token 失败：' . ($data['errmsg'] ?? 'Unknown error')
            );
        }

        self::$tokenCache[$cacheKey] = [
            'token'      => $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 7200) - 300,
        ];

        return $data['access_token'];
    }

    /**
     * 格式化模板数据（添加 value + color 包装）
     */
    private function formatTemplateData(array $data): array
    {
        $formatted = [];
        foreach ($data as $key => $item) {
            if (is_string($item)) {
                $formatted[$key] = ['value' => $item, 'color' => '#173177'];
            } elseif (is_array($item) && isset($item['value'])) {
                $formatted[$key] = [
                    'value' => $item['value'],
                    'color' => $item['color'] ?? '#173177',
                ];
            }
        }
        return $formatted;
    }

    private function postJson(string $url, array $data): string
    {
        try {
            $client = $this->getHttpClient();
            $response = $client->post($url, [
                'json'             => $data,
                'timeout'          => 10,
                'connect_timeout'  => 5,
                'headers'          => ['Content-Type' => 'application/json; charset=utf-8'],
            ]);
            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('微信公众号 HTTP 请求失败：' . $e->getMessage(), 0, $e);
        }
    }

    private function getJson(string $url): string
    {
        try {
            $client = $this->getHttpClient();
            $response = $client->get($url, [
                'timeout'         => 10,
                'connect_timeout' => 5,
            ]);
            return (string) $response->getBody();
        } catch (GuzzleException $e) {
            throw new \RuntimeException('微信公众号 HTTP GET 请求失败：' . $e->getMessage(), 0, $e);
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
