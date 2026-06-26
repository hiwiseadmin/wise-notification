<?php

declare(strict_types=1);

namespace wise\notification\channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * WecomChannel — 企业微信应用消息通道驱动
 *
 * 通过企业微信应用消息 API 发送消息，支持 text/markdown/news 类型。
 * 使用企业微信 access_token 鉴权，access_token 自动缓存（7200秒）。
 *
 * API 文档：https://developer.work.weixin.qq.com/document/path/90236
 */
class WecomChannel implements ChannelDriver
{
    private ?Client $httpClient = null;

    /** access_token 内存缓存 */
    private static array $tokenCache = [];

    public function getName(): string
    {
        return 'wecom';
    }

    /**
     * 发送企业微信应用消息
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config = config('wise-notification.channels.wecom', []);
        $corpId = $config['corp_id'] ?? '';
        $agentId = (int) ($config['agent_id'] ?? 0);
        $secret = $config['secret'] ?? '';
        $startTime = microtime(true);

        try {
            if (empty($corpId) || empty($agentId) || empty($secret)) {
                throw new \RuntimeException('企业微信发送失败：corp_id/agent_id/secret 未配置');
            }

            // 获取接收者 ID（支持字符串和数组）
            $toUser = $notifiable->routeNotificationFor('wecom');
            if (is_array($toUser)) {
                $toUser = implode('|', $toUser);
            }
            if (empty($toUser)) {
                throw new \RuntimeException('企业微信发送失败：接收者 ID 为空');
            }

            // 获取 access_token
            $accessToken = $this->getAccessToken($corpId, $secret);

            // 构造消息体
            $msgType = $message->get('msgtype', 'text');
            $body = [
                'touser'  => $toUser,
                'msgtype' => $msgType,
                'agentid' => $agentId,
            ];

            // 可选：指定部门
            $toParty = $message->get('to_party');
            if ($toParty !== null) {
                $body['toparty'] = $toParty;
            }
            $toTag = $message->get('to_tag');
            if ($toTag !== null) {
                $body['totag'] = $toTag;
            }

            // 按消息类型拼装内容
            switch ($msgType) {
                case 'markdown':
                    $body['markdown'] = ['content' => $message->content];
                    break;
                case 'textcard':
                    $body['textcard'] = [
                        'title'       => $message->title,
                        'description' => $message->content,
                        'url'         => $message->get('url', ''),
                        'btntxt'      => $message->get('btntxt', '详情'),
                    ];
                    break;
                case 'news':
                    $articles = $message->get('articles', []);
                    if (empty($articles)) {
                        $articles = [[
                            'title'       => $message->title,
                            'description' => mb_substr($message->content, 0, 512),
                            'url'         => $message->get('url', ''),
                            'picurl'      => $message->get('picurl', ''),
                        ]];
                    }
                    $body['news'] = ['articles' => $articles];
                    break;
                default: // text
                    $body['text'] = ['content' => $message->content];
                    break;
            }

            // 可选：安全模式
            $safe = $message->get('safe', 0);
            if ($safe) {
                $body['safe'] = 1;
            }

            $response = $this->postJson(
                "https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$accessToken}",
                $body
            );
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $responseData = json_decode($response, true);
            $errcode = $responseData['errcode'] ?? -1;

            if ($errcode === 0) {
                return new SendResult(
                    success: true,
                    channel: 'wecom',
                    response: $response,
                    durationMs: $durationMs,
                );
            }

            return new SendResult(
                success: false,
                channel: 'wecom',
                response: $response,
                error: $responseData['errmsg'] ?? 'Unknown error',
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'wecom',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 获取企业微信 access_token
     *
     * 使用内存缓存，默认有效期 7200 秒（提前 300 秒刷新）。
     */
    private function getAccessToken(string $corpId, string $secret): string
    {
        $cacheKey = "wecom_token_{$corpId}";

        // 检查缓存
        if (isset(self::$tokenCache[$cacheKey])) {
            $cached = self::$tokenCache[$cacheKey];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
        }

        $response = $this->getJson(
            "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid={$corpId}&corpsecret={$secret}"
        );
        $data = json_decode($response, true);

        if (($data['errcode'] ?? -1) !== 0) {
            throw new \RuntimeException(
                '企业微信获取 access_token 失败：' . ($data['errmsg'] ?? 'Unknown error')
            );
        }

        self::$tokenCache[$cacheKey] = [
            'token'      => $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 7200) - 300,
        ];

        return $data['access_token'];
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
            throw new \RuntimeException('企业微信 HTTP 请求失败：' . $e->getMessage(), 0, $e);
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
            throw new \RuntimeException('企业微信 HTTP GET 请求失败：' . $e->getMessage(), 0, $e);
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
