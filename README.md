# wiseadmin/wise-notification

> ThinkPHP 8 多通道消息通知组件：8 个内置通道、模板引擎、中间件管道、事件驱动、队列重试与熔断、脱敏与关键消息豁免。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)

---

## 特性

| 模块 | 说明 |
|------|------|
| **8 个内置通道** | 邮件（PHPMailer/SMTP）、短信（阿里云/腾讯云）、钉钉机器人、企业微信应用消息、飞书机器人、通用 Webhook、微信公众号模板消息、站内信 |
| **Manager-Driver 模式** | `NotificationManager` 统一调度，`extend()` 注册自定义通道驱动 |
| **消息模板** | `NotificationTemplate` 模型 + 自研 `TemplateCompiler`（变量/默认值/条件/循环、多语言、变量校验） |
| **同步/异步** | `sync()` 同步发送；默认走队列 `SendNotificationJob` |
| **中间件管道** | 实现 `NotificationMiddleware` 即可插入发送前后逻辑（默认启用偏好检查） |
| **偏好与节流** | 用户偏好开关、免打扰时段、窗口频率限制、用户/通道日配额 |
| **关键消息豁免** | 验证码/安全告警/站内信等事务型通知跳过日配额与免打扰，避免被静默丢弃 |
| **批量发送** | `batch()` 分片入队 + 片间延迟（`batch.delay`），返回 `BatchSendResult` |
| **失败处理** | 指数退避重试、死信落库（可重放）、渠道级断路器、失败不重试的历史防膨胀 |
| **发送历史与审计** | `NotificationHistory` 逐条记录；`NotificationAuditLog` 承载批量/节流/重试/死信等策略事件 |
| **落库脱敏** | `NotificationHistory::sanitize()` 强制脱敏 + 截断，作为敏感数据入库的最后一道防线 |
| **事件驱动** | `NotificationSending`（可 `halt()`）/ `NotificationSent` / `NotificationFailed` |
| **配置合并** | 宿主配置优先覆盖包默认配置（`ServiceTrait::mergeConfig`） |

---

## 安装

```bash
composer require wiseadmin/wise-notification
```

按需安装通道 SDK（可选依赖，未安装时对应通道会返回明确错误而非静默失败）：

```bash
composer require phpmailer/phpmailer         # 邮件
composer require alibabacloud/dysmsapi       # 阿里云短信
composer require tencentcloud/sms            # 腾讯云短信
```

安装后执行迁移（模板、发送历史、站内信、偏好、审计日志 5 张表）：

```bash
php think migrate:run
```

---

## 配置

完整配置项见包内 `config/wise-notification.php`（宿主可在 `config/wise-notification.php` 覆盖，
未声明的键会保留包默认值）。**注意列表型配置（如 `middleware`）会被宿主整体替换，不是追加。**

关键配置项：

| 配置键 | 默认值 | 说明 |
|---|---|---|
| `default` | `email` | 默认通道 |
| `sync` | `false` | 是否默认同步发送 |
| `channels.*` | — | 各通道凭证，全部来自 `.env`（见下方"通道配置速查"） |
| `channels.webhook.allow_message_endpoint` | `false` | 是否允许消息级 `webhook_url` 覆盖配置端点（SSRF 开关） |
| `queue.connection` / `queue.queue` | `redis` / `notification` | 异步队列连接与队列名 |
| `middleware` | `[PreferenceCheckMiddleware::class]` | 全局中间件列表 |
| `template.engine_mode` | `extended` | `simple`（仅变量替换）/ `extended`（条件 + 循环 + 默认值） |
| `template.allow_object_access` | `false` | 是否允许模板读取对象属性/getter |
| `template.allow_raw_output` | `false` | 是否允许 `{!var!}` 原样输出未转义 HTML |
| `retry.max_attempts` / `retry.backoff` | `5` / `[1,5,15,60,240]` | 最大重试次数 / 退避梯度（分钟） |
| `throttle.enabled` / `window` / `max_per_user` | `true` / `30` / `10` | 窗口频率限制 |
| `throttle.cache_store` | `redis` | 节流计数缓存驱动（留空则用 wise-support RateLimiter 默认的 `file`） |
| `throttle.critical.types` / `channels` | `['verify_code','security']` / `['database']` | 关键消息豁免命中条件 |
| `quota.per_user_daily` | `20` | 每用户·每通道每日配额（0 = 不限制） |
| `quota.per_channel_daily.*` | 见配置 | 每通道每日配额（0 = 不限制） |
| `circuit.failure_threshold` / `timeout` | `5` / `60` | 断路器阈值与冷却时间 |
| `circuit.cache_store` | `null` | 断路器状态缓存通道（null = 应用默认缓存） |
| `audit.mask_fields` | 15 条规则 | 落库脱敏规则（`字段名 => ['pattern','replacement']`） |
| `history_retention_days` | `90` | 发送历史保留天数（0 = 永久保留） |
| `history_max_payload_bytes` | `65000` | 单条历史 payload/response/error 最大字节数 |

### 安全相关开关

| 开关 | 默认 | 行为 |
|---|---|---|
| `audit.mask_fields` | 已内置 | `NotificationHistory` 落库前强制脱敏（验证码/密码/Token 整字段替换，手机号/邮箱部分遮蔽），调用方无法绕过 |
| `throttle.critical` | 已内置 | 关键消息跳过**日配额**与**免打扰**，但**仍受窗口频率限制**；用户显式关闭通道的偏好依然生效 |
| `channels.webhook.allow_message_endpoint` | `false` | 关闭时消息级 `webhook_url` 直接拒绝；开启后目标 URL 会校验协议白名单与内网/保留地址（解析失败即拒绝） |
| `channels.webhook` 消息级 `headers` | 永久限制 | 不允许覆盖 `Authorization` / `Cookie` / `Host` / `X-Forwarded-*` 等敏感头；HTTP 客户端不跟随重定向 |
| `template.allow_object_access` / `allow_raw_output` | `false` | 关闭模板对对象属性的访问、`{!var!}` 降级为转义输出 |

### 容错策略

- **节流/配额**：计数后端（redis 等）不可用时 **fail-open**（放行 + 日志），不因保护措施自身故障阻断投递；
- **断路器**：缓存通道不可用时降级为「不熔断」；
- **脱敏/审计**：脱敏在所有写入路径强制生效；审计写入失败不影响主流程。

---

## 使用指南

### 1. 接收者模型（Notifiable）

`Notifiable` 接口有 6 个方法，**推荐直接使用 `NotifiableTrait`**（已提供默认实现），只覆写需要的部分：

```php
namespace app\model;

use think\Model;
use wise\notification\contract\Notifiable;
use wise\notification\traits\NotifiableTrait;

class User extends Model implements Notifiable
{
    use NotifiableTrait;

    // 可选：覆写通道路由（trait 默认按 email/phone/id 等属性映射）
    public function routeNotificationFor(string $channel): string|array
    {
        return match ($channel) {
            'email'    => (string) $this->email,
            'sms'      => (string) $this->mobile,
            'database' => (string) $this->id,
            default    => (string) $this->id,
        };
    }

    // 可选：免打扰时段 / 每日上限
    public function quietHours(): ?array
    {
        return ['start' => '22:00', 'end' => '08:00'];
    }
}
```

> 仅实现 `routeNotificationFor()` 会触发 PHP 致命错误（接口方法未实现），请务必使用 trait 或补齐 6 个方法。

### 2. 发送通知（Facade）

```php
use wise\notification\facade\Notification;
use wise\notification\contract\Message;

$user = User::find(1);

// 单通道（异步，走队列）
Notification::channel('email')->send($user, new Message(
    title: '欢迎注册',
    content: '感谢您注册我们的平台！',
    data: ['name' => $user->name],
));

// 多通道同步发送
Notification::channel(['email', 'sms', 'database'])->sync()->send($user, new Message(
    title: '订单通知',
    content: '您的订单已发货',
    data: ['order_id' => '20250101001'],
));

// 使用模板（$message 作为模板变量）
Notification::channel(['email', 'database'])
    ->template('welcome')
    ->send($user, ['name' => $user->name, 'link' => 'https://example.com/welcome']);

// 优先级：urgent 会同时豁免免打扰与日配额
Notification::channel('sms')->priority('urgent')->send($user, $message);
```

### 3. 批量发送

```php
$users = User::where('status', 1)->select();

$result = Notification::channel(['email', 'database'])->batch($users, new Message(
    title: '系统维护通知',
    content: '系统将于今晚升级',
), ['chunk' => 100, 'delay' => 1]);   // delay = 片间间隔（秒）

$result->total;         // 总数
$result->successCount;  // 成功数
$result->failedCount;   // 失败数
$result->getFailed();   // 失败明细
```

### 4. 模板语法（TemplateCompiler）

```php
$message = new Message(
    title: '欢迎 {name}',
    content: '您的验证码是 {code|000000}，{expire} 分钟内有效',
    data: ['name' => '张三', 'code' => '123456', 'expire' => 5],
);
```

支持的语法：`{var}`、`{user.name}`、`{var|默认值}`、`{!raw!}`（需开启 `template.allow_raw_output`）、
`{% if var %}` / `{% else %}` / `{% endif %}`、`{% for item in items %}` / `{% endfor %}`。
默认全部 HTML 转义。

### 5. 异步发送与 Worker

```bash
php think queue:work --queue=notification
```

失败自动按 `retry.backoff` 退避重试；超过 `retry.max_attempts` 后落库为
`notification_histories.status = 'dead'`（死信），可重放。

### 6. 自定义通道驱动

```php
use wise\notification\channel\ChannelDriver;
use wise\notification\contract\{Notifiable, Message, SendResult};

class WechatChannel implements ChannelDriver
{
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $openid = $notifiable->routeNotificationFor('wechat');

        return new SendResult(
            success: true,
            channel: $this->getName(),
            response: 'ok',
        );
    }

    public function getName(): string
    {
        return 'wechat';
    }
}

Notification::extend('wechat', new WechatChannel());
```

### 7. 事件监听

```php
// app/event.php
return [
    'listen' => [
        // Sending 事件可调用 halt() 阻止发送（会被审计与失败事件记录）
        \wise\notification\event\NotificationSending::class => [\app\listener\AuditSending::class],
        \wise\notification\event\NotificationFailed::class  => [\app\listener\NotifyFailure::class],
    ],
];
```

### 8. 控制台命令

```bash
# 测试发送
php think wise:notification:send --channel=email --to=user@example.com --title="测试" --content="内容" --sync

# 重试失败记录（--dead 含死信）
php think wise:notification:retry-failed --channel=email --date=2026-09-19 --max=100 --dead

# 通道连通性 + 当日配额使用
php think wise:notification:health
php think wise:notification:health --channel=sms

# 清理过期发送历史（默认取 history_retention_days）
php think wise:notification:clean-history --days=90
php think wise:notification:clean-history --dead-only
```

> `wise:notification:clean-history` 已登记到 `config/wise-crontab.php`，默认每天 03:30 执行。

---

## 通道配置速查

所有凭证均通过 `.env` 注入（`NotificationServiceProvider::envSeeds()` 已声明占位）。

### 邮件（email）

| 环境变量 | 说明 |
|---|---|
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_ENC` | SMTP 地址 / 端口（587 TLS、465 SSL）/ 加密方式 |
| `SMTP_USER` / `SMTP_PASS` | 认证凭据 |
| `MAIL_FROM` / `MAIL_NAME` | 发件人地址 / 名称 |

### 短信（sms）

| 环境变量 | 说明 |
|---|---|
| `SMS_PROVIDER` | `aliyun`（默认）/ `tencent` / `none` |
| `SMS_COUNTRY_CODE` | 默认国际区号（默认 `+86`） |
| `ALI_SMS_KEY` / `ALI_SMS_SECRET` / `ALI_SMS_SIGN` | 阿里云 AccessKey / 签名 |
| `TENCENT_SMS_ID` / `TENCENT_SMS_KEY` / `TENCENT_SMS_APP` / `TENCENT_SMS_SIGN` | 腾讯云 SecretId / SecretKey / AppId / 签名 |

> 模板 ID 通过 `channels.sms.<provider>.template_map` 按消息 `type` 映射，或由消息 `template_code` 直接指定。
> 通道会解析网关响应码，业务失败（签名不合法、余额不足等）会返回失败而非误报成功。

### 钉钉（dingtalk）

| 环境变量 | 说明 |
|---|---|
| `DINGTALK_WEBHOOK` | 机器人 Webhook 地址（发送 URL 加签，timestamp + `\n` + secret） |
| `DINGTALK_SECRET` | 加签密钥（可选） |

### 企业微信（wecom）

| 环境变量 | 说明 |
|---|---|
| `WECOM_CORP_ID` / `WECOM_AGENT_ID` / `WECOM_SECRET` | 企业 ID / 应用 AgentId（必须为数字）/ 应用 Secret |

> access_token 由 `AccessTokenCache` 跨进程共享缓存 + 分布式锁防并发刷新。

### 飞书（feishu）

| 环境变量 | 说明 |
|---|---|
| `FEISHU_WEBHOOK` | 机器人 Webhook 地址 |
| `FEISHU_SECRET` | 签名密钥（可选）。加签时 `timestamp`/`sign` 随**请求体**发送，算法为 `HMAC_SHA256(key = timestamp + "\n" + secret, data = "")` |

### 通用 Webhook（webhook）

| 环境变量 | 说明 |
|---|---|
| `WEBHOOK_ENDPOINT` | 目标端点（默认只允许来自配置，见上文 SSRF 开关） |
| `WEBHOOK_AUTH_TOKEN` | `auth.method = bearer` 时使用 |
| `WEBHOOK_HMAC_SECRET` | `auth.method = hmac` 时使用（HMAC-SHA256） |

支持 `body_template`（`{{title}}` / `{{var.nested}}` 占位符）与消息级 `raw_body` 透传；
成功判定支持 `success_codes` 与自定义正则 `success_pattern`。

### 微信公众号（wechat_oa）

| 环境变量 | 说明 |
|---|---|
| `WECHAT_OA_APP_ID` / `WECHAT_OA_APP_SECRET` | 公众号 AppId / AppSecret |
| `WECHAT_OA_TEMPLATE_ID` | 默认模板 ID（可由消息 `template_id` 覆盖） |

### 站内信（database）

无需凭证，写入 `user_notifications` 表；同时命中 `throttle.critical.channels`，不参与日配额判定。

---

## 目录结构

```
wise-notification/
├── composer.json
├── config/
│   └── wise-notification.php          # 默认配置
├── database/migrations/               # 5 张表：模板/历史/站内信/偏好/审计
├── phpunit.xml.dist
├── tests/                             # 单元测试 + ThinkPHP 桩
└── src/
    ├── NotificationManager.php        # 核心调度器
    ├── NotificationServiceProvider.php
    ├── channel/                       # 8 个通道驱动 + AccessTokenCache
    ├── command/                       # send / retry-failed / health / clean-history
    ├── config/                        # NotificationSecretCheck（凭证检查）
    ├── contract/                      # Message / Notifiable / SendResult / BatchSendResult
    ├── event/                         # Sending / Sent / Failed
    ├── exception/                     # ServiceUnavailableException（熔断）
    ├── facade/Notification.php
    ├── health/ChannelHealthCheck.php
    ├── job/SendNotificationJob.php     # 队列任务（重试/死信/断路器）
    ├── middleware/                    # 管道接口 + 偏好检查 + SendContext
    ├── model/                         # 5 个模型
    ├── preference/PreferenceManager.php
    ├── quota/QuotaManager.php         # 配额只读查询（复用节流计数键）
    ├── security/DataMasker.php
    ├── template/TemplateCompiler.php
    ├── throttle/ThrottleManager.php
    └── traits/NotifiableTrait.php
```

> 模板与偏好管理：包内不注册任何 HTTP 路由/控制器，`NotificationTemplate::preview()/analyzeVariables()/
> getVariableDefinitions()` 与 `PreferenceManager::savePreference()` 等为**宿主后台 UI 预留的 API**，
> 由宿主自行接线（脚手架/控制器）。

---

## 依赖关系

- **必需**：`topthink/framework`、`topthink/think-queue`、`topthink/think-migration`、`topthink/think-orm`、`wiseadmin/wise-support`、`guzzlehttp/guzzle`、`ext-json`
- **可选（suggest）**：`phpmailer/phpmailer`（邮件）、`alibabacloud/dysmsapi`（阿里云短信）、`tencentcloud/sms`（腾讯云短信）
- **复用**：`wise-support` 的 `CircuitBreaker` / `BackoffStrategy` / `retry()` / `RateLimiter` / `DistributedLock` / `ConfigCheck` / `ExtPoint`

## 许可证

Apache-2.0，见 [LICENSE](LICENSE)。
