<?php

declare(strict_types=1);

namespace wise\notification\channel;

use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * SmsChannel — 短信通道驱动
 *
 * 支持阿里云短信（alibabacloud/dysmsapi）和腾讯云短信（tencentcloud/sms）两种提供商。
 * 通过 config('wise-notification.channels.sms.provider') 选择。
 */
class SmsChannel implements ChannelDriver
{
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
        $config = config('wise-notification.channels.sms', []);
        $provider = $config['provider'] ?? 'aliyun';
        $startTime = microtime(true);

        try {
            $phoneNumbers = $notifiable->routeNotificationFor('sms');
            if (is_array($phoneNumbers)) {
                $phoneNumbers = implode(',', $phoneNumbers);
            }

            if (empty($phoneNumbers)) {
                throw new \RuntimeException('短信发送失败：无法获取有效的手机号');
            }

            // 规范化手机号：若不以 + 开头，追加默认国际区号
            $phoneNumbers = $this->normalizePhoneNumbers($phoneNumbers, $config);

            $result = match ($provider) {
                'tencent' => $this->sendViaTencent($config, $phoneNumbers, $message),
                default   => $this->sendViaAliyun($config, $phoneNumbers, $message),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new SendResult(
                success: true,
                channel: 'sms',
                response: $result,
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
     * 通过阿里云短信发送
     *
     * @param array   $config       短信配置
     * @param string  $phoneNumbers 手机号
     * @param Message $message      消息体
     * @return string JSON 响应
     * @throws \RuntimeException 当 SDK 未安装时
     */
    private function sendViaAliyun(array $config, string $phoneNumbers, Message $message): string
    {
        $aliConfig = $config['aliyun'] ?? [];

        if (!class_exists(\AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi::class)) {
            throw new \RuntimeException(
                '阿里云短信 SDK 未安装。请执行: composer require alibabacloud/dysmsapi'
            );
        }

        $accessKeyId     = $aliConfig['access_key_id'] ?? '';
        $accessKeySecret = $aliConfig['access_secret'] ?? '';
        $signName        = $aliConfig['sign_name'] ?? '';

        // 从 template_map 获取模板 ID
        $templateCode = $message->get('template_code');
        if ($templateCode === null) {
            $templateMap = $aliConfig['template_map'] ?? [];
            // 如果未指定模板，尝试根据消息类型匹配
            $messageType = $message->get('type', '');
            $templateCode = $templateMap[$messageType] ?? '';
        }

        if (empty($templateCode)) {
            throw new \RuntimeException('短信发送失败：未配置模板 ID');
        }

        $client = new \AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi([
            'accessKeyId'     => $accessKeyId,
            'accessKeySecret' => $accessKeySecret,
        ]);

        $request = new \AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest([
            'phoneNumbers'  => $phoneNumbers,
            'signName'      => $signName,
            'templateCode'  => $templateCode,
            'templateParam' => json_encode($message->data, JSON_UNESCAPED_UNICODE),
        ]);

        $response = $client->sendSms($request);
        return json_encode($response->toMap(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 通过腾讯云短信发送
     *
     * @param array   $config       短信配置
     * @param string  $phoneNumbers 手机号
     * @param Message $message      消息体
     * @return string JSON 响应
     * @throws \RuntimeException 当 SDK 未安装时
     */
    private function sendViaTencent(array $config, string $phoneNumbers, Message $message): string
    {
        $tencentConfig = $config['tencent'] ?? [];

        if (!class_exists(\TencentCloud\Sms\V20210111\SmsClient::class)) {
            throw new \RuntimeException(
                '腾讯云短信 SDK 未安装。请执行: composer require tencentcloud/sms'
            );
        }

        $secretId  = $tencentConfig['secret_id'] ?? '';
        $secretKey = $tencentConfig['secret_key'] ?? '';
        $appId     = $tencentConfig['app_id'] ?? '';
        $signName  = $tencentConfig['sign_name'] ?? '';

        // 从 template_map 获取模板 ID
        $templateCode = $message->get('template_code');
        if ($templateCode === null) {
            $templateMap = $tencentConfig['template_map'] ?? [];
            $messageType = $message->get('type', '');
            $templateCode = $templateMap[$messageType] ?? '';
        }

        if (empty($templateCode)) {
            throw new \RuntimeException('短信发送失败：未配置模板 ID');
        }

        $credential = new \TencentCloud\Common\Credential($secretId, $secretKey);
        $client = new \TencentCloud\Sms\V20210111\SmsClient($credential, '');

        $req = new \TencentCloud\Sms\V20210111\Models\SendSmsRequest();
        $req->SmsSdkAppId    = $appId;
        $req->SignName       = $signName;
        $req->TemplateId     = $templateCode;
        $req->TemplateParamSet = array_values($message->data);
        // phoneNumbers 已通过 normalizePhoneNumbers() 规范化
        $req->PhoneNumberSet = [$phoneNumbers];

        $response = $client->SendSms($req);
        return $response->toJsonString();
    }
}
