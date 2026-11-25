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

    // ============================================================
    // 验证码策略（CodeManager）
    // 短信 / 邮件验证码的生成、校验与发送频控。
    // 校验成功即消费；失败超限作废；冷却与每日上限用于抵御短信轰炸。
    // ============================================================
    'code' => [
        'length'       => 6,     // 验证码位数（4-8）
        'ttl'          => 300,   // 有效期（秒）
        'cooldown'     => 60,    // 同一目标重发间隔（秒）
        'max_per_day'  => 10,    // 同一目标每日发送上限
        'max_attempts' => 5,     // 同一验证码最大校验失败次数
        // 正文模板（占位：{site} 站点名、{code} 验证码、{minutes} 有效分钟数）
        'template'     => '【{site}】您的验证码是 {code}，{minutes} 分钟内有效，请勿泄露给他人。',
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
            // 安全开关：是否允许消息级 webhook_url 覆盖配置端点。
            // 默认 false —— 消息载荷常来自业务数据（可能被用户输入污染），
            // 允许覆盖即构成 SSRF 通道。开启后目标 URL 会经过协议白名单
            // 与内网/保留地址拦截校验。
            'allow_message_endpoint' => false,
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
    ],

    // 全局中间件（实现 NotificationMiddleware 接口的类名列表）
    // 默认启用偏好/节流/配额检查：PreferenceCheckMiddleware 内部会调用
    // PreferenceManager::shouldBlock → ThrottleManager::shouldThrottle，
    // 后者已涵盖 per_user_daily / per_channel_daily 配额与免打扰判断
    'middleware' => [
        \wise\notification\middleware\PreferenceCheckMiddleware::class,
    ],

    // 模板全局配置
    'template' => [
        'locale'          => 'zh_CN',
        // 模板引擎模式：simple(仅变量替换) | extended(条件+循环+默认值)
        'engine_mode'     => 'extended',
        // 安全开关：是否允许模板读取变量对象（属性 / getXxx() 取值）。
        // 默认 false —— 模板正文可由后台编辑，开启后模板可越权读取传入对象
        // （如模型）的内部字段。仅在内置模板确需对象取值时开启。
        'allow_object_access' => false,
        // 安全开关：是否允许 {!var!} 原样输出（不转义 HTML）。
        // 默认 false —— 关闭时 {!var!} 降级为 HTML 转义输出，避免存储型 XSS。
        'allow_raw_output'    => false,
        // 模板变量是否默认 HTML 转义（email 等 HTML 通道需要；纯文本通道见下）。
        'escape_html'         => true,
        // 不做 HTML 转义的通道（纯文本通道）：转义会把 A&B 变成 A&amp;B、
        // ' 变成 &#039;，破坏正文并可能触发第三方模板参数校验失败。
        // 仅在单通道发送时精确生效；多通道发送沿用 escape_html 配置。
        'no_escape_channels'  => ['sms', 'dingtalk', 'wecom', 'feishu'],
    ],

    // 发送历史保留天数（0 表示永久保留），由 wise:notification:clean-history 清理
    'history_retention_days' => 90,

    // 单条历史记录的 payload / response / error 最大字节数（超出截断，防止 TEXT 超长写入失败）
    'history_max_payload_bytes' => 65000,

    // 批量发送默认配置
    'batch' => [
        'chunk' => 100,
        'delay' => 1,
    ],

    // 重试策略配置
    // 说明：死信不做独立队列投递，统一落库为 notification_histories.status='dead'，
    // 可由 `php think wise:notification:retry-failed --dead` 重放（避免出现无人消费的死信队列）。
    'retry' => [
        'max_attempts' => 5, // 最大重试次数
        // 指数退避策略（分钟）: 第N次重试延迟
        'backoff'      => [1, 5, 15, 60, 240], // 1min, 5min, 15min, 1h, 4h
    ],

    // 节流配置
    'throttle' => [
        'enabled'      => true,
        'window'       => 30,   // 频率限制窗口（秒）
        'max_per_user' => 10,   // 每用户单通道每窗口最大消息数
        // 计数后端缓存驱动：留空则沿用 RateLimiter 默认（file）。
        // 生产环境务必使用支持原子 inc() 的驱动（如 redis），否则多机部署
        // 计数不共享、并存在 TOCTOU 竞态导致配额被绕过。
        'cache_store'  => 'redis',

        // 关键消息豁免（避免日配额/免打扰误伤事务型通知）
        // 命中 types 或 channels 的消息：
        //   - 跳过「用户每日配额」「通道每日配额」——日配额只用于约束营销/批量类通知；
        //   - 跳过「免打扰时段」判定——验证码/安全告警不应因免打扰而丢失；
        //   - 仍受「窗口频率限制」（max_per_user / window）约束，防止被刷。
        // types 会依次匹配 Message.data 中的 message_type 与 type 两个键。
        'critical' => [
            'types'    => ['verify_code', 'security'],
            'channels' => ['database'],
        ],
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

    // ================================================================
    // 渠道级断路器（Circuit Breaker）
    // ================================================================
    'circuit' => [
        'failure_threshold' => 5,
        'timeout'           => 60,
        'key_prefix'        => 'wise_notification:cb',
        // 断路器状态存储的缓存驱动。null = 使用应用默认缓存通道。
        // 不再复用 queue.connection（队列连接名未必是合法 cache store，
        // 且断路器状态与队列连接语义无关）；store 不可用时自动降级为不熔断。
        'cache_store'       => null,
    ],

    // ================================================================
    // 审计与脱敏
    // ================================================================
    // 写入 notification_histories 前对敏感字段脱敏（最后一道防线，
    // 由 NotificationHistory::record() 强制执行，调用方无法绕过）。
    // 规则：字段名 => ['pattern' => 正则, 'replacement' => 替换串]
    'audit' => [
        'mask_fields' => [
            // 验证码 / 密钥类：整字段替换，避免 2FA 验证码等明文落库
            'code'          => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'password'      => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'secret'        => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'hmac_secret'   => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'token'         => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'auth_token'    => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'access_token'  => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'refresh_token' => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            // 收件标识类：部分保留，便于审计排查
            'phone'          => ['pattern' => '/(\d{3})\d{4}(\d{4})/', 'replacement' => '$1****$2'],
            'mobile'         => ['pattern' => '/(\d{3})\d{4}(\d{4})/', 'replacement' => '$1****$2'],
            'email'          => ['pattern' => '/([^@\s])[^@\s]*(@[^@\s]+)/', 'replacement' => '$1***$2'],
            'id_card'        => ['pattern' => '/(\w{3})\w+(\w{4})/', 'replacement' => '$1****$2'],
            'openid'         => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'wechat_openid'  => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'feishu_openid'  => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
            'wecom_userid'   => ['pattern' => '/^[\s\S]+$/', 'replacement' => '***'],
        ],

        // 自由文本（response / error）自动识别规则
        // 只放「命中即局部遮蔽」的模式：整字段替换类规则（code/password/token）
        // 若参与自动识别会把任意非空文本整体替换为 ***，导致历史记录失去排障价值。
        'mask_content' => [
            'phone' => ['pattern' => '/(\d{3})\d{4}(\d{4})/', 'replacement' => '$1****$2'],
            'email' => ['pattern' => '/([^@\s])[^@\s]*(@[^@\s]+)/', 'replacement' => '$1***$2'],
        ],
    ],
];
