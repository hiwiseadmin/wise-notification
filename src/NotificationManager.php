<?php

declare(strict_types=1);

namespace wise\notification;

use think\facade\Event;
use think\facade\Queue;
use wise\notification\channel\ChannelDriver;
use wise\notification\channel\EmailChannel;
use wise\notification\channel\DatabaseChannel;
use wise\notification\channel\SmsChannel;
use wise\notification\channel\DingtalkChannel;
use wise\notification\channel\WecomChannel;
use wise\notification\channel\FeishuChannel;
use wise\notification\channel\WebhookChannel;
use wise\notification\channel\WechatOfficialAccountChannel;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\contract\SendResult;
use wise\notification\contract\BatchSendResult;
use wise\notification\event\NotificationSending;
use wise\notification\event\NotificationSent;
use wise\notification\event\NotificationFailed;
use wise\notification\job\SendNotificationJob;
use wise\notification\middleware\NotificationMiddleware;
use wise\notification\middleware\SendContext;
use wise\notification\model\NotificationHistory;
use wise\notification\model\NotificationTemplate;

/**
 * NotificationManager — 核心通知管理器
 *
 * Manager-Driver 模式的核心调度器，负责：
 * - 通道选择与解析（channel/resolveDriver）
 * - 同步/异步模式切换（sync）
 * - 消息模板渲染（template）
 * - 消息优先级（priority）
 * - 延迟发送（delay）
 * - 节流合并（throttle/debounce）
 * - 单条/多通道/批量发送（send/batch）
 * - 事件触发（NotificationSending/Sent/Failed）
 * - 中间件管道执行（runMiddleware）
 * - 发送历史记录（NotificationHistory）
 * - 自定义驱动与中间件注册（extend/extendMiddleware）
 */
class NotificationManager
{
    /** @var string|array */
    private string|array $channels;

    private bool $sync;

    private ?string $templateCode = null;

    /** 消息优先级 */
    private string $priority = Message::PRIORITY_NORMAL;

    /** 延迟发送秒数（0 立即发送） */
    private int $delaySeconds = 0;

    /** @var array<string, ChannelDriver> */
    private array $drivers = [];

    /** @var NotificationMiddleware[] */
    private array $middleware = [];

    /** @var array<string, ChannelDriver> */
    private array $customDrivers = [];

    /** @var array<string, class-string<ChannelDriver>> */
    private static array $defaultDrivers = [
        'email'     => EmailChannel::class,
        'database'  => DatabaseChannel::class,
        'sms'       => SmsChannel::class,
        'dingtalk'  => DingtalkChannel::class,
        'wecom'     => WecomChannel::class,
        'feishu'    => FeishuChannel::class,
        'webhook'   => WebhookChannel::class,
        'wechat_oa' => WechatOfficialAccountChannel::class,
    ];

    public function __construct()
    {
        $this->channels = config('wise-notification.default', 'email');
        $this->sync     = (bool) config('wise-notification.sync', false);

        $middlewareClasses = config('wise-notification.middleware', []);
        foreach ($middlewareClasses as $class) {
            if (is_string($class) && class_exists($class)) {
                $instance = new $class();
                if ($instance instanceof NotificationMiddleware) {
                    $this->middleware[] = $instance;
                }
            }
        }
    }

    /** 设置通道 */
    public function channel(string|array $channels): self
    {
        $this->channels = $channels;
        return $this;
    }

    /** 同步模式 */
    public function sync(): self
    {
        $this->sync = true;
        return $this;
    }

    /** 设置模板 */
    public function template(string $code): self
    {
        $this->templateCode = $code;
        return $this;
    }

    /**
     * 设置消息优先级
     *
     * @param string $priority Message::PRIORITY_*
     * @return $this
     */
    public function priority(string $priority): self
    {
        $allowed = [Message::PRIORITY_URGENT, Message::PRIORITY_HIGH, Message::PRIORITY_NORMAL, Message::PRIORITY_LOW];
        if (in_array($priority, $allowed, true)) {
            $this->priority = $priority;
        }
        return $this;
    }

    /**
     * 延迟发送（秒）
     *
     * @param int $seconds 延迟秒数
     * @return $this
     */
    public function delay(int $seconds): self
    {
        $this->delaySeconds = max(0, $seconds);
        return $this;
    }

    /**
     * 发送通知（主入口）
     */
    public function send(Notifiable $notifiable, Message|array $message): SendResult|BatchSendResult|null
    {
        $resolvedMessage = $this->resolveMessage($message);

        // 注入优先级
        $resolvedMessage = $this->injectPriority($resolvedMessage);

        if (is_array($this->channels)) {
            $results = [];
            foreach ($this->channels as $channel) {
                $result = $this->executeSend($channel, $notifiable, $resolvedMessage);
                if ($result !== null) {
                    $results[] = $result;
                }
            }
            if (empty($results)) {
                return null;
            }
            return BatchSendResult::fromResults($results);
        }

        return $this->executeSend($this->channels, $notifiable, $resolvedMessage);
    }

    /**
     * 批量发送
     */
    public function batch(array $notifiables, Message|array $message, array $options = []): BatchSendResult
    {
        $batchConfig = config('wise-notification.batch', ['chunk' => 100, 'delay' => 1]);
        $chunkSize = (int) ($options['chunk'] ?? $batchConfig['chunk'] ?? 100);
        $delay     = (int) ($options['delay'] ?? $batchConfig['delay'] ?? 1);

        $resolvedMessage = $this->resolveMessage($message);
        $resolvedMessage = $this->injectPriority($resolvedMessage);
        $messageData = $resolvedMessage->toArray();

        $channels = is_array($this->channels) ? $this->channels : [$this->channels];
        $chunks = array_chunk($notifiables, max(1, $chunkSize));

        $pushedCount = 0;
        $retryConfig = config('wise-notification.retry', ['max_attempts' => 3, 'backoff' => [1, 5, 15]]);

        foreach ($chunks as $chunk) {
            $batchId = 'batch_' . uniqid('', true);

            foreach ($chunk as $notifiable) {
                foreach ($channels as $channel) {
                    $jobData = [
                        'channel'          => $channel,
                        'notifiable_type'  => get_class($notifiable),
                        'notifiable_id'    => $this->resolveNotifiableId($notifiable),
                        'message'          => $messageData,
                        'message_type'     => $this->templateCode ? 'template' : 'direct',
                        'priority'         => $this->priority,
                        'is_batch'         => true,
                        'batch_id'         => $batchId,
                        'retry'            => [
                            'attempt'     => 0,
                            'max_attempts' => $retryConfig['max_attempts'],
                            'backoff'     => $retryConfig['backoff'],
                        ],
                    ];

                    // 延迟发送
                    if ($this->delaySeconds > 0) {
                        $jobData['delay_until'] = time() + $this->delaySeconds;
                    }

                    $maskedPayload = $this->maskSensitiveFields($messageData);
                    $historyRecord = NotificationHistory::record([
                        'channel'         => $channel,
                        'notifiable_type' => get_class($notifiable),
                        'notifiable_id'   => $this->resolveNotifiableId($notifiable),
                        'message_type'    => $jobData['message_type'],
                        'message_payload' => json_encode($maskedPayload, JSON_UNESCAPED_UNICODE),
                        'status'          => 'pending',
                        'batch_id'        => $batchId,
                        'create_time'      => date('Y-m-d H:i:s'),
                    ]);

                    $jobData['history_id'] = $historyRecord->getKey();

                    $queueName = config('wise-notification.queue.queue', 'notification');
                    // 延迟队列：使用 delay 参数
                    if ($this->delaySeconds > 0) {
                        Queue::later($this->delaySeconds, SendNotificationJob::class, $jobData, $queueName);
                    } else {
                        Queue::push(SendNotificationJob::class, $jobData, $queueName);
                    }
                    $pushedCount++;
                }
            }
        }

        $results = [];
        for ($i = 0; $i < $pushedCount; $i++) {
            $results[] = new SendResult(
                success: true,
                channel: 'queue',
                response: 'Pushed to queue',
                status: SendResult::STATUS_QUEUED,
            );
        }

        return BatchSendResult::fromResults($results);
    }

    /** 核心调度 */
    public function dispatch(string $channel, Notifiable $notifiable, Message $message): SendResult
    {
        $sendingEvent = new NotificationSending(
            manager: $this,
            channel: $channel,
            notifiable: $notifiable,
            message: $message,
        );

        Event::trigger($sendingEvent);

        if ($sendingEvent->halted) {
            $result = new SendResult(
                success: false,
                channel: $channel,
                error: 'halted by NotificationSending event',
            );
            $this->recordHistory($channel, $notifiable, $message, $result, $this->templateCode);
            return $result;
        }

        $driver = $this->resolveDriver($channel);

        $context = new SendContext(
            channel: $channel,
            notifiable: $notifiable,
            message: $message,
        );
        // 注入优先级到上下文
        $context->extra = ['priority' => $this->priority];

        $startTime = microtime(true);
        try {
            $result = $this->runMiddleware($context, $driver);
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $result = new SendResult(
                success: false,
                channel: $channel,
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }

        $maskedPayload = $this->maskSensitiveFields($message->toArray());
        $maskedMessage = new Message(title: $message->title, content: $message->content, data: $maskedPayload['data'] ?? $message->data);
        $this->recordHistory($channel, $notifiable, $maskedMessage, $result, $this->templateCode);

        if ($result->success) {
            Event::trigger(new NotificationSent(
                manager: $this,
                channel: $channel,
                notifiable: $notifiable,
                result: $result,
            ));
        } else {
            Event::trigger(new NotificationFailed(
                manager: $this,
                channel: $channel,
                notifiable: $notifiable,
                result: $result,
            ));
        }

        return $result;
    }

    /** 获取驱动 */
    public function resolveDriver(string $channel): ChannelDriver
    {
        if (isset($this->drivers[$channel])) {
            return $this->drivers[$channel];
        }

        if (isset($this->customDrivers[$channel])) {
            $this->drivers[$channel] = $this->customDrivers[$channel];
            return $this->drivers[$channel];
        }

        if (isset(self::$defaultDrivers[$channel])) {
            $driverClass = self::$defaultDrivers[$channel];
            $this->drivers[$channel] = new $driverClass();
            return $this->drivers[$channel];
        }

        throw new \RuntimeException("未注册的通知通道驱动: {$channel}");
    }

    public function extend(string $name, ChannelDriver $driver): void
    {
        $this->customDrivers[$name] = $driver;
        unset($this->drivers[$name]);
    }

    public function extendMiddleware(NotificationMiddleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function createMessage(string $title = '', string $content = '', array $data = []): Message
    {
        return new Message(title: $title, content: $content, data: $data);
    }

    public function runMiddleware(SendContext $context, ChannelDriver $driver): SendResult
    {
        // 节流检查中间件（内置，无需配置）
        $finalHandler = function (SendContext $ctx) use ($driver): SendResult {
            return $driver->send($ctx->notifiable, $ctx->message);
        };

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, NotificationMiddleware $middleware) {
                return function (SendContext $ctx) use ($middleware, $next): SendResult {
                    return $middleware->handle($ctx, $next);
                };
            },
            $finalHandler,
        );

        return $pipeline($context);
    }

    // ==================== 私有方法 ====================

    private function executeSend(string $channel, Notifiable $notifiable, Message $message): SendResult|null
    {
        if ($this->sync) {
            return $this->dispatch($channel, $notifiable, $message);
        }

        $messageData = $message->toArray();
        $notifiableId = $this->resolveNotifiableId($notifiable);
        $messageType = $this->templateCode ? 'template' : 'direct';
        $retryConfig = config('wise-notification.retry', ['max_attempts' => 3, 'backoff' => [1, 5, 15]]);

        $maskedPayload = $this->maskSensitiveFields($messageData);
        $historyRecord = NotificationHistory::record([
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $notifiableId,
            'message_type'    => $messageType,
            'message_payload' => json_encode($maskedPayload, JSON_UNESCAPED_UNICODE),
            'status'          => 'pending',
            'create_time'      => date('Y-m-d H:i:s'),
        ]);

        $jobData = [
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $notifiableId,
            'message'         => $messageData,
            'message_type'    => $messageType,
            'priority'        => $this->priority,
            'is_batch'        => false,
            'batch_id'        => null,
            'history_id'      => $historyRecord->getKey(),
            'retry'           => [
                'attempt'     => 0,
                'max_attempts' => $retryConfig['max_attempts'],
                'backoff'     => $retryConfig['backoff'],
            ],
        ];

        if ($this->delaySeconds > 0) {
            $jobData['delay_until'] = time() + $this->delaySeconds;
        }

        $queueName = config('wise-notification.queue.queue', 'notification');
        if ($this->delaySeconds > 0) {
            Queue::later($this->delaySeconds, SendNotificationJob::class, $jobData, $queueName);
        } else {
            Queue::push(SendNotificationJob::class, $jobData, $queueName);
        }

        return null;
    }

    private function resolveMessage(Message|array $message): Message
    {
        if ($message instanceof Message) {
            return $message;
        }

        if ($this->templateCode !== null) {
            $locale = config('wise-notification.template.locale', 'zh_CN');
            $template = NotificationTemplate::getByCode($this->templateCode, $locale);

            if ($template === null) {
                throw new \RuntimeException(
                    "消息模板未找到: code={$this->templateCode}, locale={$locale}"
                );
            }

            return $template->render($message);
        }

        return new Message(
            title: $message['title'] ?? '',
            content: $message['content'] ?? '',
            data: $message,
        );
    }

    /** 将优先级注入到 Message 的 data 中 */
    private function injectPriority(Message $message): Message
    {
        if (!isset($message->data['_priority'])) {
            return new Message(
                title: $message->title,
                content: $message->content,
                data: array_merge($message->data, ['_priority' => $this->priority]),
            );
        }
        return $message;
    }

    private function resolveNotifiableId(Notifiable $notifiable): string
    {
        if (method_exists($notifiable, 'getKey')) {
            return (string) $notifiable->getKey();
        }

        try {
            $id = $notifiable->routeNotificationFor('database');
            if (is_array($id)) {
                return (string) ($id[0] ?? '');
            }
            return (string) $id;
        } catch (\Throwable) {
            return '';
        }
    }

    private function recordHistory(
        string $channel,
        Notifiable $notifiable,
        Message $message,
        SendResult $result,
        ?string $templateCode,
    ): void {
        NotificationHistory::record([
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $this->resolveNotifiableId($notifiable),
            'message_type'    => $templateCode ? 'template' : 'direct',
            'message_payload' => json_encode($message->toArray(), JSON_UNESCAPED_UNICODE),
            'status'          => $result->success ? 'sent' : 'failed',
            'response'        => $result->response,
            'error'           => $result->error,
            'duration_ms'     => $result->durationMs,
            'create_time'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 敏感字段脱敏
     */
    private function maskSensitiveFields(array $messageData): array
    {
        $maskConfig = config('wise-notification.audit.mask_fields', []);
        if (empty($maskConfig)) {
            return $messageData;
        }

        $masked = $messageData;
        foreach ($maskConfig as $field => $rule) {
            if (isset($masked['data'][$field]) && is_string($masked['data'][$field])) {
                $masked['data'][$field] = preg_replace(
                    $rule['pattern'],
                    $rule['replacement'],
                    $masked['data'][$field]
                );
            }
        }

        return $masked;
    }
}
