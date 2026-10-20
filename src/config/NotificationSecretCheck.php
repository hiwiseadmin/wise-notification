<?php
declare(strict_types=1);

namespace wise\notification\config;

use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-notification 通道密钥检测
 *
 * 检测默认通道和已配置通道的密钥完整性。
 * 通道被视为"已配置"当至少一个凭证字段非空。
 */
class NotificationSecretCheck implements ConfigCheckInterface
{
    /**
     * 各通道必填凭证映射
     *
     * sms 由 channelSpecs() 按 provider 动态展开，此处不静态声明。
     * 仅列入「缺失即通道不可用」的字段：可选项（dingtalk/feishu 的加签 secret、
     * wechat_oa 的默认模板 ID）不列入，避免大量误报。
     *
     * @var array<string, array{fields: string[], env: string[]}>
     */
    private const CHANNEL_CREDENTIALS = [
        'email' => [
            'fields' => ['host', 'username', 'password'],
            'env'    => ['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'],
        ],
        'dingtalk' => [
            'fields' => ['webhook_url'],
            'env'    => ['DINGTALK_WEBHOOK'],
        ],
        'wecom' => [
            'fields' => ['corp_id', 'agent_id', 'secret'],
            'env'    => ['WECOM_CORP_ID', 'WECOM_AGENT_ID', 'WECOM_SECRET'],
        ],
        'feishu' => [
            'fields' => ['webhook_url'],
            'env'    => ['FEISHU_WEBHOOK'],
        ],
        'webhook' => [
            'fields' => ['endpoint'],
            'env'    => ['WEBHOOK_ENDPOINT'],
        ],
        'wechat_oa' => [
            'fields' => ['app_id', 'app_secret'],
            'env'    => ['WECHAT_OA_APP_ID', 'WECHAT_OA_APP_SECRET'],
        ],
    ];

    public function __construct(
        private readonly \think\Config $config,
    ) {
    }

    public function id(): string
    {
        return 'wise-notification:secret';
    }

    public function package(): string
    {
        return 'wise-notification';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::ERROR;
    }

    public function check(): CheckResult
    {
        $channels = (array) $this->config->get('wise-notification.channels', []);
        $warnings = [];

        foreach ($this->channelSpecs($channels) as $channel => $spec) {
            $values = [];
            foreach ($spec['fields'] as $field) {
                $values[] = $this->getNested($channels, $channel . '.' . $field);
            }

            // 判断通道是否"已配置"：至少一个凭证有值
            $hasAny = false;
            $hasAll = true;
            foreach ($values as $v) {
                if ($v !== '' && $v !== null) {
                    $hasAny = true;
                } else {
                    $hasAll = false;
                }
            }

            // 有部分配置但不完整 → 可能是想用但漏配了
            if ($hasAny && !$hasAll) {
                $missing = [];
                foreach ($spec['env'] as $i => $env) {
                    $v = $values[$i] ?? '';
                    if ($v === '' || $v === null) {
                        $missing[] = $env;
                    }
                }
                $warnings[] = "$channel 通道凭证不完整，缺少: " . implode(', ', $missing);
            }
        }

        // 条件项：仅在选择了对应认证模式时才要求密钥
        $authMethod = (string) ($this->getNested($channels, 'webhook.auth.method') ?? 'none');
        if ($authMethod === 'bearer' && empty($this->getNested($channels, 'webhook.auth.token'))) {
            $warnings[] = 'webhook 通道 auth.method=bearer 但缺少 auth.token（WEBHOOK_AUTH_TOKEN）';
        }
        if ($authMethod === 'hmac' && empty($this->getNested($channels, 'webhook.auth.hmac_secret'))) {
            $warnings[] = 'webhook 通道 auth.method=hmac 但缺少 auth.hmac_secret（WEBHOOK_HMAC_SECRET）';
        }

        if (!empty($warnings)) {
            return CheckResult::fail(
                '通知通道凭证不完整: ' . implode('; ', $warnings),
                '请检查 .env 中对应通道的环境变量配置'
            );
        }

        return CheckResult::ok();
    }

    /**
     * 展开各通道凭证规格
     *
     * sms 按当前 provider 只检查对应服务商的字段，
     * 避免「配了 tencent 却报 aliyun 字段缺失」这类误报。
     *
     * @param array $channels 通道配置
     * @return array<string, array{fields: string[], env: string[]}>
     */
    private function channelSpecs(array $channels): array
    {
        $specs = self::CHANNEL_CREDENTIALS;

        $provider = (string) ($this->getNested($channels, 'sms.provider') ?? 'aliyun');
        $specs['sms'] = match ($provider) {
            'tencent' => [
                'fields' => ['tencent.secret_id', 'tencent.secret_key', 'tencent.app_id', 'tencent.sign_name'],
                'env'    => ['TENCENT_SMS_ID', 'TENCENT_SMS_KEY', 'TENCENT_SMS_APP', 'TENCENT_SMS_SIGN'],
            ],
            'none'    => ['fields' => [], 'env' => []],
            default   => [
                'fields' => ['aliyun.access_key_id', 'aliyun.access_secret', 'aliyun.sign_name'],
                'env'    => ['ALI_SMS_KEY', 'ALI_SMS_SECRET', 'ALI_SMS_SIGN'],
            ],
        };

        return array_filter($specs, static fn (array $spec): bool => ($spec['fields'] ?? []) !== []);
    }

    /**
     * 按 '.' 分隔的路径获取嵌套数组值
     */
    private function getNested(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
