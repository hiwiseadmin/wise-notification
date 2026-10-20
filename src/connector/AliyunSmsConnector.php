<?php
declare(strict_types=1);

namespace wise\notification\connector;

use wise\support\connector\AbstractConnector;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;
use wise\support\connector\SmsConnectorInterface;

/**
 * 阿里云短信连接器
 *
 * 将阿里云短信（alibabacloud/dysmsapi）封装为连接器，使短信能力统一纳入
 * ConnectorManager 管理（发现 / 健康检查 / 配置 / 能力自描述）。
 *
 * 消费方式：
 * <pre>
 * app(\wise\support\connector\ConnectorManager::class)
 *     ->sms()   // 或 driver('aliyun_sms')
 *     ->send('13800000000', 'SMS_123456', ['code' => '1234']);
 * </pre>
 *
 * 配置来源：config('wise-notification.channels.sms.aliyun')
 *
 * @package wise\notification\connector
 */
class AliyunSmsConnector extends AbstractConnector implements SmsConnectorInterface
{
    public function getId(): string
    {
        return 'aliyun_sms';
    }

    public function getName(): string
    {
        return '阿里云短信';
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::MESSAGE;
    }

    public function getDescription(): string
    {
        return '阿里云短信服务（dysmsapi）';
    }

    public function getActions(): array
    {
        return [
            'send'       => ['description' => '发送单条短信', 'params' => ['phone', 'template_code', 'params']],
            'send_batch' => ['description' => '批量发送短信', 'params' => ['phones', 'template_code', 'params']],
        ];
    }

    /**
     * 动态分发：把 action 路由到强类型方法
     */
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
            default      => ConnectorResult::fail(sprintf('阿里云短信连接器不支持操作 [%s]', $action), 'UNSUPPORTED_ACTION'),
        };
    }

    public function healthCheck(): bool
    {
        return class_exists(\AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi::class);
    }

    public function getConfigSchema(): array
    {
        return [
            ['key' => 'access_key_id', 'type' => 'text', 'label' => 'AccessKey ID', 'required' => true],
            ['key' => 'access_secret', 'type' => 'password', 'label' => 'AccessKey Secret', 'required' => true],
            ['key' => 'sign_name', 'type' => 'text', 'label' => '短信签名', 'required' => true],
            ['key' => 'template_map', 'type' => 'json', 'label' => '模板映射（type → 模板 Code）', 'required' => false],
        ];
    }

    public function send(string $phone, string $templateCode, array $params = [], array $options = []): ConnectorResult
    {
        try {
            if (!class_exists(\AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi::class)) {
                throw new \RuntimeException('阿里云短信 SDK 未安装。请执行: composer require alibabacloud/dysmsapi');
            }

            $config = $options['config'] ?? (array) config('wise-notification.channels.sms.aliyun', []);
            $accessKeyId     = $config['access_key_id'] ?? '';
            $accessKeySecret = $config['access_secret'] ?? '';
            $signName        = $options['sign_name'] ?? ($config['sign_name'] ?? '');

            if ($phone === '') {
                throw new \RuntimeException('短信发送失败：手机号为空');
            }
            if ($templateCode === '') {
                throw new \RuntimeException('短信发送失败：未配置模板 ID');
            }

            $client  = new \AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi([
                'accessKeyId'     => $accessKeyId,
                'accessKeySecret' => $accessKeySecret,
            ]);

            $request = new \AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest([
                'phoneNumbers'  => $phone,
                'signName'      => $signName,
                'templateCode'  => $templateCode,
                'templateParam' => json_encode($params, JSON_UNESCAPED_UNICODE),
            ]);

            $response = $client->sendSms($request);
            $map      = method_exists($response, 'toMap') ? (array) $response->toMap() : [];
            $body     = is_array($map['body'] ?? null) ? $map['body'] : $map;
            $code     = strtoupper((string) ($body['Code'] ?? ''));
            $status   = (int) ($map['statusCode'] ?? 0);

            // 识别到 Code 时严格判定；SDK 返回结构无法识别时，仅当 HTTP 2xx 视为成功。
            $ok = $code !== ''
                ? $code === 'OK'
                : ($status >= 200 && $status < 300);

            $json = json_encode($map, JSON_UNESCAPED_UNICODE);

            return $ok
                ? ConnectorResult::ok(['success' => true, 'response' => $json], ['provider' => 'aliyun'])
                : ConnectorResult::fail(
                    (string) ($body['Message'] ?? ($code !== '' ? $code : '阿里云短信返回失败')),
                    'SMS_SEND_FAILED',
                    $json
                );
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

            // 阿里云 sendSms 支持逗号分隔多号码，复用单条逻辑
            $single = $this->send(implode(',', $phones), $templateCode, $params, $options);
            if ($single->isFailure()) {
                return $single;
            }

            return ConnectorResult::ok([
                'success'     => count($phones),
                'failed'      => 0,
                'failed_list' => [],
                'response'    => $single->getData()['response'] ?? null,
            ], ['provider' => 'aliyun']);
        } catch (\Throwable $e) {
            return ConnectorResult::fail($e->getMessage(), 'SMS_ERROR');
        }
    }
}
