<?php

declare(strict_types=1);

namespace wise\notification\channel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * FeishuChannel — 飞书 Bot 消息通道驱动
 *
 * 通过飞书自定义机器人 Webhook 发送消息，支持 text/interactive/post 类型。
 * 支持签名校验（timestamp + sign），使用 GuzzleHttp 发送。
 *
 * API 文档：https://open.feishu.cn/document/client-docs/bot-v3/add-custom-bot
 */
class FeishuChannel implements ChannelDriver
{
    private ?Client $httpClient = null;

    public function getName(): string
    {
        return 'feishu';
    }

    /**
     * 发送飞书机器人消息
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config = config('wise-notification.channels.feishu', []);
        $webhookUrl = $config['webhook_url'] ?? '';
        $secret = $config['secret'] ?? '';
        $startTime = microtime(true);

        try {
            if (empty($webhookUrl)) {
                throw new \RuntimeException('飞书发送失败：webhook_url 未配置');
            }

            // 构造签名 URL
            $finalUrl = $webhookUrl;
            if (!empty($secret)) {
                $finalUrl = $this->buildSignedUrl($webhookUrl, $secret);
            }

            // 确定消息类型（默认 interactive）
            $msgType = $message->get('msgtype', 'interactive');

            // 构造消息体
            $body = $this->buildMessageBody($msgType, $message);

            // 发送请求
            $response = $this->postJson($finalUrl, $body);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $responseData = json_decode($response, true);
            $code = $responseData['code'] ?? $responseData['StatusCode'] ?? -1;

            if ($code === 0) {
                return new SendResult(
                    success: true,
                    channel: 'feishu',
                    response: $response,
                    durationMs: $durationMs,
                );
            }

            return new SendResult(
                success: false,
                channel: 'feishu',
                response: $response,
                error: $responseData['msg'] ?? $responseData['StatusMessage'] ?? 'Unknown error',
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'feishu',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 按消息类型构造飞书消息体
     */
    private function buildMessageBody(string $msgType, Message $message): array
    {
        switch ($msgType) {
            case 'text':
                return [
                    'msg_type' => 'text',
                    'content'  => [
                        'text' => $message->content,
                    ],
                ];

            case 'post':
                $postContent = $message->get('post_content');
                return [
                    'msg_type' => 'post',
                    'content'  => [
                        'post' => $postContent ?? [
                            'zh_cn' => [
                                'title'   => $message->title,
                                'content' => [[
                                    ['tag' => 'text', 'text' => $message->content],
                                ]],
                            ],
                        ],
                    ],
                ];

            case 'image':
                return [
                    'msg_type' => 'image',
                    'content'  => [
                        'image_key' => $message->get('image_key', ''),
                    ],
                ];

            case 'share_chat':
                return [
                    'msg_type' => 'share_chat',
                    'content'  => [
                        'chat_id' => $message->get('chat_id', ''),
                    ],
                ];

            case 'interactive':
            default:
                // 如果 data 中有 cards 结构，直接使用
                $cards = $message->get('cards');
                if ($cards !== null) {
                    return [
                        'msg_type' => 'interactive',
                        'card' => is_array($cards) ? $cards : json_decode($cards, true),
                    ];
                }

                // 否则构造简单的标题+内容卡片
                return [
                    'msg_type' => 'interactive',
                    'card'     => $this->buildSimpleCard($message),
                ];
        }
    }

    /**
     * 构造简单的飞书卡片
     */
    private function buildSimpleCard(Message $message): array
    {
        $cardTitle = $message->get('card_title', $message->title);
        $elements = $message->get('card_elements', []);

        if (empty($elements)) {
            $elements = [[
                'tag'  => 'markdown',
                'content' => $message->content,
            ]];
        }

        return [
            'header' => [
                'title'    => ['tag' => 'plain_text', 'content' => $cardTitle],
                'template' => $message->get('card_header_color', 'blue'),
            ],
            'elements' => $elements,
        ];
    }

    /**
     * 构造飞书签名 URL
     *
     * 签名算法：timestamp + "\n" + secret → HMAC-SHA256 → base64
     */
    private function buildSignedUrl(string $webhookUrl, string $secret): string
    {
        $timestamp = (string) time();
        $stringToSign = $timestamp . "\n" . $secret;
        $sign = base64_encode(hash_hmac('sha256', '', $secret, true));
        // Correct HMAC: hash the timestamp+secret string
        $sign = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        return $webhookUrl . (str_contains($webhookUrl, '?') ? '&' : '?')
            . 'timestamp=' . $timestamp . '&sign=' . urlencode($sign);
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
            throw new \RuntimeException('飞书 HTTP 请求失败：' . $e->getMessage(), 0, $e);
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
