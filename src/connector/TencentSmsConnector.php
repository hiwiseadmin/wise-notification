<?php
declare(strict_types=1);

namespace wise\notification\connector;

use wise\support\connector\AbstractConnector;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;
use wise\support\connector\SmsConnectorInterface;

/**
 * 腾讯云短信连接器
 *
 * 将腾讯云短信（tencentcloud/sms）封装为连接器，使短信能力统一纳入
 * ConnectorManager 管理（发现 / 健康检查 / 配置 / 能力自描述）。
 *
 * 消费方式：
 * <pre>
 * app(\wise\support\connector\ConnectorManager::class)
 *     ->sms()   // 或 driver('tencent_sms')
 *     ->send('+8613800000000', '123456', ['1' => '8888']);
 * </pre>
 *
 * 配置来源：config('wise-notification.channels.sms.tencent')
 *
 * @package wise\notification\connector
 */
class TencentSmsConnector extends AbstractConnector implements SmsConnectorInterface
{
    public function getId(): string
    {
        return 'tencent_sms';
    }

    public function getName(): string
    {
        return '腾讯云短信';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::MESSAGE;
    }

    public function getDescription(): string
    {
        return '腾讯云短信服务（tencentcloud/sms）';
    }

    public function getActions(): array
    {
        return [
            'send'       => ['description' => '发送单条短信', 'params' => ['phone', 'template_code', 'params']],
            'send_batch' => ['description' => '批量发送短信', 'params' => ['phones', 'template_code', 'params']],
        ];
    }

    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        return match ($action) {
            'send'       => $this->send(
                (string) ($input['phone'] ?? ''),
                (string) ($input['template_code'] ?? ''),
                (array) ($input['params'] ?? []),
                (array) ($input['options'] ?? [])
            ),
            'send_batch' => $this->sendBatch(
                (array) ($input['phones'] ?? []),
                (string) ($input['template_code'] ?? ''),
                (array) ($input['params'] ?? []),
                (array) ($input['options'] ?? [])
            ),
            default      => ConnectorResult::fail(sprintf('腾讯云短信连接器不支持操作 [%s]', $action), 'UNSUPPORTED_ACTION'),
        };
    }

    public function healthCheck(): bool
    {
        return class_exists(\TencentCloud\Sms\V20210111\SmsClient::class);
    }

    public function getConfigSchema(): array
    {
        return [
            ['key' => 'secret_id', 'type' => 'text', 'label' => 'SecretId', 'required' => true],
            ['key' => 'secret_key', 'type' => 'password', 'label' => 'SecretKey', 'required' => true],
            ['key' => 'app_id', 'type' => 'text', 'label' => '短信应用 SdkAppId', 'required' => true],
            ['key' => 'sign_name', 'type' => 'text', 'label' => '短信签名', 'required' => true],
            ['key' => 'template_map', 'type' => 'json', 'label' => '模板映射（type → 模板 ID）', 'required' => false],
        ];
    }

    public function send(string $phone, string $templateCode, array $params = [], array $options = []): ConnectorResult
    {
        try {
            if (!class_exists(\TencentCloud\Sms\V20210111\SmsClient::class)) {
                throw new \RuntimeException('腾讯云短信 SDK 未安装。请执行: composer require tencentcloud/sms');
            }

            $config    = $options['config'] ?? (array) config('wise-notification.channels.sms.tencent', []);
            $secretId  = $config['secret_id'] ?? '';
            $secretKey = $config['secret_key'] ?? '';
            $appId     = $config['app_id'] ?? '';
            $signName  = $options['sign_name'] ?? ($config['sign_name'] ?? '');

            if ($phone === '') {
                throw new \RuntimeException('短信发送失败：手机号为空');
            }
            if ($templateCode === '') {
                throw new \RuntimeException('短信发送失败：未配置模板 ID');
            }

            $credential = new \TencentCloud\Common\Credential($secretId, $secretKey);
            $client     = new \TencentCloud\Sms\V20210111\SmsClient($credential, '');

            $req                  = new \TencentCloud\Sms\V20210111\Models\SendSmsRequest();
            $req->SmsSdkAppId     = $appId;
            $req->SignName        = $signName;
            $req->TemplateId      = $templateCode;
            // 腾讯云要求每个模板参数独立成字符串元素
            $req->TemplateParamSet = array_map('strval', array_values($params));
            // phone 可能为逗号分隔的多号码，必须拆成独立元素
            $req->PhoneNumberSet  = explode(',', $phone);

            $response = $client->SendSms($req);
            $json     = method_exists($response, 'toJsonString') ? (string) $response->toJsonString() : '';
            $data     = json_decode($json, true);

            $statusSet = is_array($data['SendStatusSet'] ?? null) ? $data['SendStatusSet'] : [];

            if ($statusSet === []) {
                return ConnectorResult::fail(
                    (string) ($data['Response']['Error']['Message'] ?? '腾讯云短信响应缺少 SendStatusSet，无法判定发送结果'),
                    'SMS_SEND_FAILED',
                    $json
                );
            }

            $failed = [];
            foreach ($statusSet as $item) {
                $code = (string) ($item['Code'] ?? '');
                if ($code !== 'Ok') {
                    $failed[] = ($item['PhoneNumber'] ?? '?') . ': ' . ($item['Message'] ?? $code);
                }
            }

            return $failed === []
                ? ConnectorResult::ok(['success' => true, 'response' => $json], ['provider' => 'tencent'])
                : ConnectorResult::fail(implode('; ', $failed), 'SMS_SEND_FAILED', $json);
        } catch (\Throwable $e) {
            return ConnectorResult::fail($e->getMessage(), 'SMS_ERROR');
        }
    }

    public function sendBatch(array $phones, string $templateCode, array $params = [], array $options = []): ConnectorResult
    {
        try {
            $phones = array_values(array_filter(array_map('strval', $phones), static fn(string $p): bool => $p !== ''));
            if ($phones === []) {
                throw new \RuntimeException('短信发送失败：手机号列表为空');
            }

            $single = $this->send(implode(',', $phones), $templateCode, $params, $options);
            if ($single->isFailure()) {
                return $single;
            }

            return ConnectorResult::ok([
                'success'     => count($phones),
                'failed'      => 0,
                'failed_list' => [],
                'response'    => $single->getData()['response'] ?? null,
            ], ['provider' => 'tencent']);
        } catch (\Throwable $e) {
            return ConnectorResult::fail($e->getMessage(), 'SMS_ERROR');
        }
    }
}
