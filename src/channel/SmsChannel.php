<?php

declare(strict_types=1);

namespace wise\notification\channel;

use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;
use wise\support\connector\ConnectorManager;
use wise\support\connector\SmsConnectorInterface;

/**
 * SmsChannel — 短信通道驱动（通知语义适配层）
 *
 * 本类不再直接调用短信 SDK，而是作为「通道层 → 连接器层」的适配器：
 *   - 通道层：把 Notifiable / Message 翻译为短信原始参数；
 *   - 连接器层：由 SmsConnector（阿里云短信 / 腾讯云短信，见 connector/ 目录）
 *     负责实际的 SDK 调用，统一纳入 ConnectorManager 管理。
 *
 * 服务商选择：config('wise-notification.channels.sms.provider')，取值 aliyun / tencent / none。
 *
 * 兼容性：对外契约（ChannelDriver::send(Notifiable, Message)）保持不变，
 * 现有调用方与配置均无需改动。
 */
class SmsChannel implements \wise\notification\connector\NotificationConnectorInterface
{
    use \wise\notification\connector\NotificationConnectorTrait;

    /**
     * 获取通道名称
     *
     * @return string
     */
    public function getName(): string
    {
        return 'sms';
    }

    /**
     * 发送短信通知
     *
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @return SendResult
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $config   = config('wise-notification.channels.sms', []);
        $provider = (string) ($config['provider'] ?? 'aliyun');
        $startTime = microtime(true);

        try {
            if ($provider === 'none') {
                throw new \RuntimeException('短信发送失败：短信通道未启用（provider=none）');
            }
            if (!in_array($provider, ['aliyun', 'tencent'], true)) {
                throw new \RuntimeException("短信发送失败：不支持的服务商 provider={$provider}");
            }

            // 通道语义 → 短信原始参数
            $phone        = $this->resolvePhone($notifiable, $config);
            $templateCode = $this->resolveTemplateCode($message, $config, $provider);
            $params       = $this->templateParams($message);

            // 委托连接器层执行 SDK 调用
            $connector = $this->resolveConnector($provider);
            $result    = $connector->send($phone, $templateCode, $params, [
                'config' => (array) ($config[$provider] ?? []),
            ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new SendResult(
                success: $result->isSuccess(),
                channel: 'sms',
                response: $this->extractResponse($result),
                error: $result->isSuccess() ? null : $result->getError(),
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'sms',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * 解析接收手机号并规范化国际区号
     *
     * @param Notifiable $notifiable 接收者
     * @param array      $config     短信配置
     * @return string
     */
    protected function resolvePhone(Notifiable $notifiable, array $config): string
    {
        $phoneNumbers = $notifiable->routeNotificationFor('sms');
        if (is_array($phoneNumbers)) {
            $phoneNumbers = implode(',', $phoneNumbers);
        }

        if (empty($phoneNumbers)) {
            throw new \RuntimeException('短信发送失败：无法获取有效的手机号');
        }

        return $this->normalizePhoneNumbers((string) $phoneNumbers, $config);
    }

    /**
     * 解析短信模板 Code / ID
     *
     * @param Message $message      消息体
     * @param array   $config       短信配置
     * @param string  $provider     服务商
     * @return string
     */
    protected function resolveTemplateCode(Message $message, array $config, string $provider): string
    {
        $templateCode = $message->get('template_code');

        if ($templateCode === null) {
            $templateMap  = $config[$provider]['template_map'] ?? [];
            $messageType  = (string) $message->get('type', '');
            $templateCode = $templateMap[$messageType] ?? '';
        }

        return (string) ($templateCode ?? '');
    }

    /**
     * 解析短信连接器：优先按配置的服务商取，回退到当前活跃短信连接器
     *
     * @param string $provider 服务商（aliyun / tencent）
     * @return SmsConnectorInterface
     */
    protected function resolveConnector(string $provider): SmsConnectorInterface
    {
        $manager = app(ConnectorManager::class);

        $name = match ($provider) {
            'aliyun'  => 'aliyun_sms',
            'tencent' => 'tencent_sms',
            default   => null,
        };

        if ($name !== null) {
            try {
                $connector = $manager->driver($name);
                if ($connector instanceof SmsConnectorInterface) {
                    return $connector;
                }
            } catch (\Throwable $e) {
                // 指定服务商的连接器不可用（未注册/实例化失败）→ 回退到活跃连接器
            }
        }

        return $manager->sms();
    }

    /**
     * 从连接器结果提取原始响应文本
     *
     * @param \wise\support\connector\ConnectorResult $result 连接器结果
     * @return string
     */
    protected function extractResponse(\wise\support\connector\ConnectorResult $result): string
    {
        if ($result->isSuccess()) {
            $data = $result->getData();
            return is_array($data) ? (string) ($data['response'] ?? '') : '';
        }

        return (string) ($result->getRaw() ?? '');
    }

    /**
     * 规范化手机号：对不以 + 开头的号码追加默认国际区号
     *
     * @param string $phoneNumbers 逗号分隔的手机号字符串
     * @param array  $config       短信配置
     * @return string 规范化后的手机号字符串
     */
    private function normalizePhoneNumbers(string $phoneNumbers, array $config): string
    {
        $defaultCountryCode = $config['default_country_code'] ?? '+86';
        $numbers = explode(',', $phoneNumbers);
        $normalized = [];

        foreach ($numbers as $number) {
            $number = trim($number);
            if ($number === '') {
                continue;
            }
            // 如果号码已经以 + 开头，保留原始格式
            if (str_starts_with($number, '+')) {
                $normalized[] = $number;
            } else {
                $normalized[] = $defaultCountryCode . $number;
            }
        }

        return implode(',', $normalized);
    }

    /**
     * 提取短信模板参数
     *
     * 剔除 wise-notification 内部键（如 _priority），避免污染第三方模板参数
     * 导致参数个数/顺序错位、模板校验失败。
     *
     * @return array<string, mixed>
     */
    private function templateParams(Message $message): array
    {
        return array_filter(
            $message->data,
            static fn (mixed $key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
