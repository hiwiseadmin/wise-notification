# wiseadmin/wise-notification

> ThinkPHP 8 多通道消息通知组件，支持邮件、短信、钉钉、站内信等通道，提供模板、中间件、事件驱动能力。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--notification-orange)](https://packagist.org/packages/wiseadmin/wise-notification)

---

## 特性

| 模块 | 说明 |
|------|------|
| **四通道内置** | 邮件（PHPMailer）、短信（阿里云/腾讯云双provider）、钉钉机器人（Webhook + 签名）、站内信 |
| **Manager-Driver 模式** | `NotificationManager` 统一调度，支持注册自定义通道驱动 |
| **消息模板** | `NotificationTemplate` 模型，支持多语言、变量替换 |
| **同步/异步** | `sync()` 同步发送，不调用时默认走队列 (`SendNotificationJob`) |
| **中间件管道** | 可追加 `NotificationMiddleware`，在发送前后插入自定义逻辑 |
| **批量发送** | `batch()` 方法，Chunk 分批 + Delay 控制，返回 `BatchSendResult` |
| **发送历史** | `NotificationHistory` 模型，记录每次发送结果（状态/耗时/错误） |
| **事件驱动** | `NotificationSending` / `NotificationSent` / `NotificationFailed` |
| **合并配置** | 支持与 ThinkPHP 应用配置递归合并 |

---

## 安装

```bash
composer require wiseadmin/wise-notification
```

按需安装通道 SDK：

```bash
# 邮件
composer require phpmailer/phpmailer

# 阿里云短信
composer require alibabacloud/dysmsapi

# 腾讯云短信
composer require tencentcloud/sms
```

> **提示**：以上通道 SDK 为可选依赖，按实际使用的通道安装即可。

---

## 配置

发布配置文件：

```bash
php think wise:notification:publish
```

配置文件 `config/wise-notification.php`：

```php
return [
    // 默认通道
    'default' => 'email',

    // 是否同步发送（false 则走队列）
    'sync' => false,

    // 通道配置
    'channels' => [
        // 邮件
        'email' => [
            'host'       => env('MAIL_HOST', 'smtp.example.com'),
            'port'       => env('MAIL_PORT', 587),
            'username'   => env('MAIL_USERNAME'),
            'password'   => env('MAIL_PASSWORD'),
            'encryption' => 'tls',
            'from'       => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
                'name'    => env('MAIL_FROM_NAME', '系统通知'),
            ],
        ],

        // 短信
        'sms' => [
            'provider'             => 'aliyun',    // aliyun / tencent
            'default_country_code' => '+86',
            'aliyun' => [
                'access_key_id'     => env('SMS_ALIYUN_ACCESS_KEY_ID'),
                'access_secret'     => env('SMS_ALIYUN_ACCESS_SECRET'),
                'sign_name'         => '您的签名',
                'template_map'      => [
                    'verify_code' => 'SMS_123456789',
                    'order_notify' => 'SMS_987654321',
                ],
            ],
            'tencent' => [
                'secret_id'   => env('SMS_TENCENT_SECRET_ID'),
                'secret_key'  => env('SMS_TENCENT_SECRET_KEY'),
                'app_id'      => env('SMS_TENCENT_APP_ID'),
                'sign_name'   => '您的签名',
                'template_map' => [
                    'verify_code' => '1234567',
                ],
            ],
        ],

        // 钉钉机器人
        'dingtalk' => [
            'webhook_url' => env('DINGTALK_WEBHOOK_URL'),
            'secret'      => env('DINGTALK_SECRET'),
        ],

        // 站内信
        'database' => [],
    ],

    // 批量发送配置
    'batch' => [
        'chunk' => 100,    // 每批100人
        'delay' => 1,      // 批次间间隔1秒
    ],

    // 队列配置
    'queue' => [
        'queue' => 'notification',
    ],

    // 模板语言
    'template' => [
        'locale' => 'zh_CN',
    ],

    // 中间件
    'middleware' => [],
];
```

---

## 使用指南

### 1. 接收者模型（Notifiable）

让用户模型实现 `Notifiable` 接口：

```php
namespace app\model;

use think\Model;
use wise\notification\contract\Notifiable;

class User extends Model implements Notifiable
{
    public function routeNotificationFor(string $channel): string|array
    {
        return match ($channel) {
            'email'    => $this->email,
            'sms'      => $this->mobile,
            'database' => $this->id,
            default    => '',
        };
    }
}
```

### 2. 发送通知（Facade）

```php
use wise\notification\facade\Notification;
use wise\notification\contract\Message;

$user = User::find(1);

// 单通道发送
Notification::channel('email')->send($user, new Message(
    title: '欢迎注册',
    content: '感谢您注册我们的平台！',
    data: ['name' => $user->name]
));

// 多通道同步发送
Notification::channel(['email', 'sms', 'database'])
    ->sync()
    ->send($user, new Message(
        title: '订单通知',
        content: '您的订单 #{order_id} 已发货',
        data: ['order_id' => '20250101001']
    ));

// 使用模板
Notification::channel(['email', 'database'])
    ->template('welcome')
    ->send($user, ['name' => $user->name, 'link' => 'https://example.com/welcome']);
```

### 3. 批量发送

```php
$users = User::where('status', 1)->select();

$result = Notification::channel(['email', 'database'])
    ->batch($users, new Message(
        title: '系统维护通知',
        content: '系统将于 {time} 进行维护升级，敬请谅解。',
        data: ['time' => '2025-01-15 02:00']
    ), ['chunk' => 100, 'delay' => 1]);

// $result 包含每个通道的发送统计
echo "成功: {$result->success}, 失败: {$result->failure}";
```

### 4. 创建消息

```php
use wise\notification\contract\Message;

// 基本消息
$message = new Message('标题', '正文内容');

// 带模版变量的消息（{name} 会被替换）
$message = new Message('欢迎 {name}', '您的验证码是 {code}，{expire}分钟内有效', [
    'name'   => '张三',
    'code'   => '123456',
    'expire' => '5',
]);

// 读取属性
echo $message->title;   // "欢迎 {name}"
echo $message->content; // "您的验证码是..."
echo $message->get('code');  // '123456'

// 转数组
$array = $message->toArray();
```

### 5. 异步发送（队列）

默认不调用 `sync()` 时，通知通过队列异步发送：

```php
// 异步发送（放入队列）
Notification::channel('email')->send($user, $message);
// 底层：dispatch(new SendNotificationJob('email', $user, $message))

// 在队列中启动 worker
// php think queue:work --queue=notification
```

### 6. 依赖注入方式

```php
use wise\notification\NotificationManager;

class OrderController
{
    public function shipped(NotificationManager $notification, $orderId)
    {
        $order = Order::find($orderId);
        $user  = $order->user;

        $notification->channel(['email', 'sms'])
            ->send($user, new Message(
                '订单已发货',
                "订单 #{$order->order_no} 已发货，快递单号: {$order->tracking_no}"
            ));
    }
}
```

### 7. 自定义通道驱动

```php
use wise\notification\channel\ChannelDriver;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

class WechatChannel implements ChannelDriver
{
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        $openid = $notifiable->routeNotificationFor('wechat');

        // 调用微信公众号/小程序发送逻辑
        // ...

        return SendResult::success($this->getName());
    }

    public function getName(): string
    {
        return 'wechat';
    }
}

// 注册到管理器
$manager = app(NotificationManager::class);
$manager->extend('wechat', new WechatChannel());

// 使用
Notification::channel('wechat')->send($user, $message);
```

### 8. 中间件管道

```php
use wise\notification\middleware\NotificationMiddleware;
use wise\notification\middleware\SendContext;
use wise\notification\channel\ChannelDriver;
use wise\notification\contract\SendResult;

class LoggingMiddleware implements NotificationMiddleware
{
    public function handle(SendContext $context, callable $next): SendResult
    {
        // 发送前记录
        Log::info("Sending via {$context->channel->getName()} to {$context->notifiable}");

        $result = $next($context);

        // 发送后记录
        Log::info("Result: {$result->status}");

        return $result;
    }
}

// 注册中间件
$manager = app(NotificationManager::class);
$manager->extendMiddleware(new LoggingMiddleware());
```

### 9. 事件监听

```php
// app/event.php
return [
    'listen' => [
        \wise\notification\event\NotificationFailed::class => [
            \app\listener\NotificationFailureAlert::class,
        ],
    ],
];
```

### 10. 控制台命令

```bash
# 发布配置
php think wise:notification:publish

# 测试发送
php think wise:notification:send --channel=email --to=user@example.com --title="测试" --content="这是一条测试消息"
```

---

## 通道配置速查

### 邮件

| 配置项 | 说明 |
|--------|------|
| `host` | SMTP 服务器地址 |
| `port` | 端口（587 TLS / 465 SSL） |
| `username` / `password` | 认证凭据 |
| `encryption` | `tls` / `ssl` |
| `from.address` | 发件人邮箱 |
| `from.name` | 发件人名称 |

### 短信

| Provider | 配置项 | 说明 |
|----------|--------|------|
| aliyun | `access_key_id` / `access_secret` | RAM 用户 AK |
| aliyun | `sign_name` | 短信签名 |
| aliyun | `template_map` | 模版CODE 映射 |
| tencent | `secret_id` / `secret_key` | 云 API 密钥 |
| tencent | `app_id` | 短信应用 ID |
| tencent | `sign_name` | 短信签名 |
| tencent | `template_map` | 模版ID 映射 |

### 钉钉机器人

| 配置项 | 说明 |
|--------|------|
| `webhook_url` | 机器人 Webhook 地址 |
| `secret` | 加签密钥（可选，用于安全设置） |

---

## 目录结构

```
wise-notification/
├── composer.json
├── config/
│   └── wise-notification.php       # 配置文件
├── database/
│   └── migrations/                 # 数据库迁移
└── src/
    ├── Service.php                 # 服务提供者
    ├── NotificationManager.php     # 核心通知管理器
    ├── channel/
    │   ├── ChannelDriver.php       # 通道驱动接口
    │   ├── EmailChannel.php        # 邮件通道
    │   ├── SmsChannel.php          # 短信通道
    │   ├── DingtalkChannel.php     # 钉钉机器人通道
    │   └── DatabaseChannel.php     # 站内信通道
    ├── command/
    │   ├── Send.php                # 控制台发送命令
    │   └── Publish.php             # 发布配置命令
    ├── contract/
    │   ├── Message.php             # 消息值对象
    │   ├── Notifiable.php          # 接收者接口
    │   └── SendResult.php          # 发送结果（含批量结果）
    ├── event/
    │   ├── NotificationSending.php
    │   ├── NotificationSent.php
    │   └── NotificationFailed.php
    ├── facade/
    │   └── Notification.php        # Facade
    ├── job/
    │   └── SendNotificationJob.php # 异步队列任务
    ├── middleware/
    │   ├── NotificationMiddleware.php
    │   └── SendContext.php
    ├── model/
    │   ├── NotificationHistory.php
    │   ├── NotificationTemplate.php
    │   └── UserNotification.php
    └── trait/
        ├── ServiceTrait.php
        ├── CommandTrait.php
        └── NotifiableTrait.php
```

---

## License

MIT
