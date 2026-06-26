<?php

declare(strict_types=1);

/**
 * wise-notification 全局配置文件
 *
 * 包含通道参数、队列配置、模板设置、重试策略、偏好配置等全部配置项。
 */
return [
    // 默认发送通道
    'default' => 'email',

    // 是否默认同步发送（跳过队列），默认 false 即异步
    'sync' => false,

    // 表名配置
    'table_names' => [
        'templates'          => 'notification_templates',
        'histories'          => 'notification_histories',
        'user_notifications'  => 'user_notifications',
        'preferences'         => 'notification_preferences',
        'audit_logs'          => 'notification_audit_logs',
    ],

    // 通道配置
    'channels' => [
        'email' => [
            'driver'     => 'smtp',
            'host'       => env('SMTP_HOST', ''),
            'port'       => env('SMTP_PORT', 587),
            'username'   => env('SMTP_USER', ''),
            'password'   => env('SMTP_PASS', ''),
            'encryption' => env('SMTP_ENC', 'tls'),
            'from'       => [
                'address' => env('MAIL_FROM', ''),
                'name'    => env('MAIL_NAME', ''),
            ],
        ],

        'sms' => [
            'provider'             => env('SMS_PROVIDER', 'aliyun'),
            'default_country_code' => env('SMS_COUNTRY_CODE', '+86'),
            'aliyun'   => [
                'access_key_id' => env('ALI_SMS_KEY', ''),
                'access_secret' => env('ALI_SMS_SECRET', ''),
                'sign_name'     => env('ALI_SMS_SIGN', ''),
                'template_map'  => [],
            ],
            'tencent'  => [
                'secret_id'    => env('TENCENT_SMS_ID', ''),
                'secret_key'   => env('TENCENT_SMS_KEY', ''),
                'app_id'       => env('TENCENT_SMS_APP', ''),
                'sign_name'    => env('TENCENT_SMS_SIGN', ''),
                'template_map' => [],
            ],
        ],

        'dingtalk' => [
            'webhook_url' => env('DINGTALK_WEBHOOK', ''),
            'secret'      => env('DINGTALK_SECRET', ''),
        ],

        // 企业微信应用消息
        'wecom' => [
            'corp_id'  => env('WECOM_CORP_ID', ''),
            'agent_id' => env('WECOM_AGENT_ID', ''),
            'secret'   => env('WECOM_SECRET', ''),
        ],

        // 飞书自定义机器人
        'feishu' => [
            'webhook_url' => env('FEISHU_WEBHOOK', ''),
            'secret'      => env('FEISHU_SECRET', ''),
        ],

        // 通用 Webhook
        'webhook' => [
            'endpoint'          => env('WEBHOOK_ENDPOINT', ''),
            'timeout'           => 10,
            'connect_timeout'   => 5,
            'headers'           => [
                'User-Agent' => 'WiseAdmin-Notification/1.0',
            ],
            // 认证配置
            'auth' => [
                'method'      => 'none', // none | bearer | hmac
                'token'       => env('WEBHOOK_AUTH_TOKEN', ''),
                'hmac_secret' => env('WEBHOOK_HMAC_SECRET', ''),
                'hmac_header' => 'X-Signature',
            ],
            // 请求体模板（支持 {{title}} {{content}} {{var.nested}} 占位符）
            'body_template'     => null,
            // 成功判定：code 等于以下值即成功
            'success_codes'     => [0, 200],
            // 自定义成功正则（优先级高于 success_codes）
            'success_pattern'   => null,
        ],

        // 微信公众号模板消息
        'wechat_oa' => [
            'app_id'               => env('WECHAT_OA_APP_ID', ''),
            'app_secret'           => env('WECHAT_OA_APP_SECRET', ''),
            'default_template_id'  => env('WECHAT_OA_TEMPLATE_ID', ''),
        ],

        'database' => [],
    ],

    // 队列配置
    'queue' => [
        'connection'  => env('NOTIFICATION_QUEUE', 'redis'),
        'queue'       => 'notification',
        'retry_after' => 300,
    ],

    // 全局中间件（实现 NotificationMiddleware 接口的类名列表）
    'middleware' => [],

    // 模板全局配置
    'template' => [
        'locale'          => 'zh_CN',
        // 模板引擎模式：simple(仅变量替换) | extended(条件+循环+默认值)
        'engine_mode'     => 'extended',
    ],

    // 发送历史保留天数（0 表示永久保留）
    'history_retention_days' => 90,

    // 批量发送默认配置
    'batch' => [
        'chunk' => 100,
        'delay' => 1,
    ],

    // 重试策略配置
    'retry' => [
        'max_attempts' => 5, // 最大重试次数
        // 指数退避策略（分钟）: 第N次重试延迟
        'backoff'      => [1, 5, 15, 60, 240], // 1min, 5min, 15min, 1h, 4h
        // 死信队列配置
        'dead_queue'   => 'notification_dead',
    ],

    // 节流合并配置
    'throttle' => [
        'enabled'      => true,
        'window'       => 30,   // 合并窗口（秒）
        'max_per_user' => 10,   // 每用户单通道每窗口最大消息数
    ],

    // 发送配额
    'quota' => [
        'per_channel_daily' => [  // 每通道每日最大发送量（0=不限制）
            'email'    => 0,
            'sms'      => 500,
            'dingtalk' => 0,
            'wecom'    => 10000,
            'feishu'   => 0,
            'webhook'  => 0,
            'wechat_oa'=> 0,
            'database' => 0,
        ],
        'per_user_daily'    => 20, // 每用户每日最大接收量
    ],

];
