<?php

declare(strict_types=1);

namespace wise\notification;

use wise\support\contracts\EnvSeedProvider;


use wise\support\traits\RegistersMigrations;
use wise\support\ExtPoint;
use wise\support\ExtPoints;
use wise\support\traits\ServiceTrait;
use wise\support\config\ConfigCheckCollector;
use wise\notification\config\NotificationSecretCheck;

/**
 * wise-notification 服务提供者
 *
 * 负责注册配置、绑定门面、注册命令。
 * 通过 composer.json 的 extra.think.services 自动加载。
 */
class NotificationServiceProvider extends \think\Service implements EnvSeedProvider
{

    use \wise\support\traits\RegistersMigrations;
    use ServiceTrait;

    /**
     * 提供的服务映射
     */
    /**
     * 服务注册
     *
     * 合并配置、绑定容器实例、注册控制台命令。
     */
    /**
     * 声明需要播种到 .env 的通知渠道凭证
     *
     * 全部为第三方凭证（短信/邮件/Webhook/微信/钉钉/飞书/企业微信），
     * 留空占位由用户填写，绝不在安装时生成假值。
     * 静态默认值（队列连接/国家码/服务商/SMTP 加密与端口）沿用 config，不写入 .env。
     */
    public static function envSeeds(): array
    {
        return [
            // 阿里云短信
            'ALI_SMS_KEY'    => ['type' => 'external', 'default' => '', 'comment' => '阿里云短信 AccessKeyId'],
            'ALI_SMS_SECRET' => ['type' => 'external', 'default' => '', 'comment' => '阿里云短信 AccessSecret'],
            'ALI_SMS_SIGN'   => ['type' => 'external', 'default' => '', 'comment' => '阿里云短信签名'],
            // 腾讯云短信
            'TENCENT_SMS_APP' => ['type' => 'external', 'default' => '', 'comment' => '腾讯云短信 AppId'],
            'TENCENT_SMS_ID'  => ['type' => 'external', 'default' => '', 'comment' => '腾讯云短信 SecretId'],
            'TENCENT_SMS_KEY' => ['type' => 'external', 'default' => '', 'comment' => '腾讯云短信 SecretKey'],
            'TENCENT_SMS_SIGN' => ['type' => 'external', 'default' => '', 'comment' => '腾讯云短信签名'],
            // SMTP 邮件
            'SMTP_HOST' => ['type' => 'external', 'default' => '', 'comment' => 'SMTP 服务器地址'],
            'SMTP_USER' => ['type' => 'external', 'default' => '', 'comment' => 'SMTP 用户名'],
            'SMTP_PASS' => ['type' => 'external', 'default' => '', 'comment' => 'SMTP 密码'],
            'MAIL_FROM' => ['type' => 'external', 'default' => '', 'comment' => '发件邮箱地址'],
            'MAIL_NAME' => ['type' => 'external', 'default' => '', 'comment' => '发件人名称'],
            // 钉钉 / 飞书机器人
            'DINGTALK_WEBHOOK' => ['type' => 'external', 'default' => '', 'comment' => '钉钉机器人 Webhook'],
            'DINGTALK_SECRET'  => ['type' => 'external', 'default' => '', 'comment' => '钉钉机器人加签密钥'],
            'FEISHU_WEBHOOK'  => ['type' => 'external', 'default' => '', 'comment' => '飞书机器人 Webhook'],
            'FEISHU_SECRET'   => ['type' => 'external', 'default' => '', 'comment' => '飞书机器人签名密钥'],
            // 自定义 Webhook
            'WEBHOOK_ENDPOINT'    => ['type' => 'external', 'default' => '', 'comment' => '自定义 Webhook 端点'],
            'WEBHOOK_AUTH_TOKEN'  => ['type' => 'external', 'default' => '', 'comment' => '自定义 Webhook 鉴权 Token'],
            'WEBHOOK_HMAC_SECRET' => ['type' => 'external', 'default' => '', 'comment' => '自定义 Webhook HMAC 密钥'],
            // 微信公众号
            'WECHAT_OA_APP_ID'         => ['type' => 'external', 'default' => '', 'comment' => '微信公众号 AppId'],
            'WECHAT_OA_APP_SECRET'     => ['type' => 'external', 'default' => '', 'comment' => '微信公众号 AppSecret'],
            'WECHAT_OA_TEMPLATE_ID'    => ['type' => 'external', 'default' => '', 'comment' => '微信公众号模板消息 ID'],
            // 企业微信
            'WECOM_CORP_ID'   => ['type' => 'external', 'default' => '', 'comment' => '企业微信 CorpId'],
            'WECOM_AGENT_ID'  => ['type' => 'external', 'default' => '', 'comment' => '企业微信应用 AgentId'],
            'WECOM_SECRET'    => ['type' => 'external', 'default' => '', 'comment' => '企业微信应用 Secret'],
        ];
    }

    public function register(): void
    {
        // 合并包配置到应用配置
        $this->mergeConfig('wise-notification');

        // 注册配置检查项
        $this->app->make(ConfigCheckCollector::class)->register(
            new NotificationSecretCheck($this->app->config)
        );

        // 绑定 NotificationManager 到容器（Facade 代理目标）
        $this->app->bind('wise.notification.manager', NotificationManager::class);
        $this->app->bind(NotificationManager::class, NotificationManager::class);

        // 绑定验证码管理器（生成 / 校验 / 频控）
        // 说明：调用方（如 wise-member 认证服务）通过本类校验短信验证码，
        // 历史实现引用的 `wise\notification\Manager` 并不存在，故统一到本类。
        $this->app->bind('wise.notification.code', CodeManager::class);
        $this->app->bind(CodeManager::class, CodeManager::class);

        // 注册 CLI 命令（仅在真正执行 php think ... 时触发）
        $this->commands([
            command\Send::class,
            command\RetryFailed::class,
            command\HealthCheck::class,
            command\CleanHistory::class,
        ]);
    }

    /**
     * 服务启动
     */
    public function boot(): void
    {
        $this->registerMigrations();

        // 将内置通道驱动注册到统一扩展点
        $this->registerNotificationExtPoints();
    }

    /**
     * 将通知通道与短信连接器注册到连接器体系（ExtPoints::EXTERNAL_CONNECTOR）
     *
     * 通过反射读取 NotificationManager::$defaultDrivers 静态映射，
     * 避免硬编码。配置中的 channel 参数（SMTP/Webhook URL 等）保持不变。
     */
    protected function registerNotificationExtPoints(): void
    {
        // 通知通道统一迁入连接器体系，取代原 notification.channel 扩展点。
        $connectors = [];

        // 1. 内置通知通道（8 个，均实现 NotificationConnectorInterface）
        try {
            $connectors = NotificationManager::getDefaultDrivers();
        } catch (\Throwable) {
            $connectors = [];
        }

        // 2. 短信服务商连接器（阿里云 / 腾讯云，实现 SmsConnectorInterface）
        $connectors += [
            'aliyun_sms'  => connector\AliyunSmsConnector::class,
            'tencent_sms' => connector\TencentSmsConnector::class,
        ];

        foreach ($connectors as $name => $class) {
            if (class_exists($class)) {
                ExtPoint::register(ExtPoints::EXTERNAL_CONNECTOR, $name, $class);
            }
        }
    }
}
