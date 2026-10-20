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
use wise\notification\model\NotificationAuditLog;
use wise\notification\model\NotificationHistory;
use wise\notification\model\NotificationTemplate;
use wise\support\traits\Conditionable;
use wise\support\traits\Tappable;

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
    use Conditionable;
    use Tappable;

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

    public function __construct(
        private readonly \think\App $app,
    ) {
        $this->channels = config('wise-notification.default', 'email');
        $this->sync     = (bool) config('wise-notification.sync', false);

        $middlewareClasses = config('wise-notification.middleware', []);
        foreach ($middlewareClasses as $class) {
            if (is_string($class) && class_exists($class)) {
                $instance = $this->app->make($class);
                if ($instance instanceof NotificationMiddleware) {
                    $this->middleware[] = $instance;
                }
            }
        }
    }

    /** @return array<string, class-string<ChannelDriver>> */
    public static function getDefaultDrivers(): array
    {
        return self::$defaultDrivers;
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
        try {
            // 单通道时可精确决定模板变量是否 HTML 转义；多通道沿用配置默认
            $resolvedMessage = $this->resolveMessage(
                $message,
                is_array($this->channels) ? null : $this->channels
            );

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
        } finally {
            // 门面返回的是同一实例：终态操作后必须复位一次性流式状态，
            // 否则 sync/priority/delay/channel 会污染后续调用（常驻进程下跨请求）
            $this->resetFluentState();
        }
    }

    /**
     * 复位一次性流式状态（保留已注册的驱动与中间件）
     *
     * Notification 门面复用同一个 Manager 实例，而 channel()/sync()/priority()/
     * delay()/template() 都是写入实例属性的流式方法；不复位会出现：
     * 某处调用 sync() 后同进程内所有发送变为同步阻塞、priority('urgent') 后
     * 所有消息被判为关键消息而绕过免打扰与日配额。
     */
    private function resetFluentState(): void
    {
        $this->channels     = config('wise-notification.default', 'email');
        $this->sync         = (bool) config('wise-notification.sync', false);
        $this->templateCode = null;
        $this->priority     = Message::PRIORITY_NORMAL;
        $this->delaySeconds = 0;
    }

    /**
     * 批量发送
     */
    public function batch(array $notifiables, Message|array $message, array $options = []): BatchSendResult
    {
        try {
            return $this->executeBatch($notifiables, $message, $options);
        } finally {
            // 同 send()：门面共享实例必须在终态操作后复位一次性流式状态
            $this->resetFluentState();
        }
    }

    /**
     * 批量发送主体（由 batch() 调用，保证异常路径也能复位状态）
     */
    private function executeBatch(array $notifiables, Message|array $message, array $options = []): BatchSendResult
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

        foreach ($chunks as $chunkIndex => $chunk) {
            $batchId = 'batch_' . uniqid('', true);
            // 批次间错峰：第 N 片整体延后 N * delay 秒入队（衔接 batch.delay 配置）
            $chunkDelay = $this->delaySeconds + ($chunkIndex * $delay);

            // 先收集本片全部任务数据与待落库历史行，再一次性批量 INSERT：
            // 原实现逐条 record()，N 收件人 × M 通道会在调用方线程内产生 N*M 次往返
            $pending = [];
            foreach ($chunk as $notifiable) {
                foreach ($channels as $channel) {
                    $jobData = [
                        'channel'         => $channel,
                        'notifiable_type' => get_class($notifiable),
                        'notifiable_id'   => $this->resolveNotifiableId($notifiable),
                        'message'         => $messageData,
                        'message_type'    => $this->templateCode ? 'template' : 'direct',
                        'is_batch'        => true,
                        'batch_id'        => $batchId,
                        'retry'           => [
                            'attempt'      => 0,
                            'max_attempts' => $retryConfig['max_attempts'],
                            'backoff'      => $retryConfig['backoff'],
                        ],
                    ];

                    // 延迟发送
                    if ($chunkDelay > 0) {
                        $jobData['delay_until'] = time() + $chunkDelay;
                    }

                    $pending[] = [
                        'job'  => $jobData,
                        'hist' => [
                            'channel'         => $channel,
                            'notifiable_type' => get_class($notifiable),
                            'notifiable_id'   => $this->resolveNotifiableId($notifiable),
                            'message_type'    => $jobData['message_type'],
                            'message_payload' => NotificationHistory::encodePayload($messageData),
                            'status'          => 'pending',
                            'batch_id'        => $batchId,
                            'create_time'     => date('Y-m-d H:i:s'),
                        ],
                    ];
                }
            }

            // 脱敏与截断由 NotificationHistory::sanitize() 在落库前统一执行
            $historyIds = $this->recordHistoryBatch(array_column($pending, 'hist'));

            $queueName = config('wise-notification.queue.queue', 'notification');
            foreach ($pending as $index => $item) {
                $jobData = $item['job'];
                $jobData['history_id'] = $historyIds[$index] ?? null;

                // 延迟队列：使用 delay 参数
                if ($chunkDelay > 0) {
                    Queue::later($chunkDelay, SendNotificationJob::class, $jobData, $queueName);
                } else {
                    Queue::push(SendNotificationJob::class, $jobData, $queueName);
                }
                $pushedCount++;
            }
        }

        // 审计：每个 batch 调用仅写一条汇总，避免逐条写审计表造成放大
        $this->writeAudit('batch', [
            'channel'         => implode(',', $channels),
            'notifiable_type' => '',
            'notifiable_id'   => '',
            'message_type'    => $this->templateCode ? 'template' : 'direct',
            'status'          => 'pending',
            'context'         => [
                'total'    => $pushedCount,
                'chunks'   => count($chunks),
                'chunk'    => $chunkSize,
                'delay'    => $delay,
                'channels' => $channels,
            ],
        ]);

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
    public function dispatch(string $channel, Notifiable $notifiable, Message $message, ?int $historyId = null): SendResult
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
            $this->recordHistory($channel, $notifiable, $message, $result, $this->templateCode, $historyId);
            // 与发送失败保持一致：触发失败事件，便于监听方告警/统计
            Event::trigger(new NotificationFailed(
                manager: $this,
                channel: $channel,
                notifiable: $notifiable,
                result: $result,
            ));
            return $result;
        }

        $driver = $this->resolveDriver($channel);

        // 优先级统一由 injectPriority() 写入 Message::$data['_priority'] 传递
        // （异步队列路径同样依赖该键，见 contract/Message::isUrgent()）；
        // SendContext::$extra 当前无消费者，不再写入死字段以免误导二次开发者
        $context = new SendContext(
            channel: $channel,
            notifiable: $notifiable,
            message: $message,
        );

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

        // 脱敏与截断由 NotificationHistory::sanitize() 在落库前统一执行
        $this->recordHistory($channel, $notifiable, $message, $result, $this->templateCode, $historyId);

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

        // 脱敏与截断由 NotificationHistory::sanitize() 在落库前统一执行
        $historyRecord = NotificationHistory::record([
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $notifiableId,
            'message_type'    => $messageType,
            'message_payload' => NotificationHistory::encodePayload($messageData),
            'status'          => 'pending',
            'create_time'      => date('Y-m-d H:i:s'),
        ]);

        $jobData = [
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $notifiableId,
            'message'         => $messageData,
            'message_type'    => $messageType,
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

        return new SendResult(
            success: true,
            channel: $channel,
            response: 'Pushed to queue',
            status: SendResult::STATUS_QUEUED,
        );
    }

    /**
     * 解析消息体
     *
     * @param Message|array $message 原始消息
     * @param string|null   $channel 目标通道（用于决定模板变量是否 HTML 转义）
     */
    private function resolveMessage(Message|array $message, ?string $channel = null): Message
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

            return $template->render($message, null, $this->escapeHtmlFor($channel));
        }

        return new Message(
            title: $message['title'] ?? '',
            content: $message['content'] ?? '',
            data: $message,
        );
    }

    /**
     * 判断该通道渲染模板时是否应做 HTML 转义
     *
     * 短信 / 钉钉 / 企微 / 飞书是纯文本通道，转义会把 A&B 变成 A&amp;B、
     * ' 变成 &#039;，既破坏正文可读性也可能触发第三方模板参数校验失败。
     * 返回 null 表示沿用 template.escape_html 配置（多通道无法区分时的兜底）。
     */
    private function escapeHtmlFor(?string $channel): ?bool
    {
        if ($channel === null) {
            return null;
        }

        $noEscape = (array) config(
            'wise-notification.template.no_escape_channels',
            ['sms', 'dingtalk', 'wecom', 'feishu']
        );

        return in_array($channel, $noEscape, true) ? false : null;
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
        ?int $historyId = null,
    ): void {
        // 队列场景：更新既有的 pending 记录，避免每次重试重复插入历史行
        if ($historyId !== null) {
            $history = NotificationHistory::find($historyId);
            if ($history) {
                // 更新路径同样经过 sanitize（脱敏 + 截断），避免 response/error 明文落库
                $history->save(NotificationHistory::sanitize([
                    'status'      => $result->success ? 'sent' : 'failed',
                    'response'    => $result->response,
                    'error'       => $result->error,
                    'duration_ms' => $result->durationMs,
                ]));
                return;
            }
        }

        NotificationHistory::record([
            'channel'         => $channel,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id'   => $this->resolveNotifiableId($notifiable),
            'message_type'    => $templateCode ? 'template' : 'direct',
            'message_payload' => NotificationHistory::encodePayload($message->toArray()),
            'status'          => $result->success ? 'sent' : 'failed',
            'response'        => $result->response,
            'error'           => $result->error,
            'duration_ms'     => $result->durationMs,
            'create_time'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 批量写入 pending 历史行（一片一次 INSERT）
     *
     * 批量入队场景下逐条 record() 会把「N 收件人 × M 通道」放大为 N*M 次数据库
     * 往返，且全部发生在调用方线程内（Web 请求 / CLI 主线程），是 batch() 的
     * 主要性能瓶颈。这里改为一次 saveAll()；批量写入不可用时降级为逐条写入，
     * 保证历史行不丢失（降级路径仍由 NotificationHistory::record() 脱敏）。
     *
     * @param array $rows 原始历史行（未脱敏）
     * @return array<int, int|string|null> 与 $rows 同序的主键，失败位置为 null
     */
    private function recordHistoryBatch(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $sanitized = [];
        foreach ($rows as $row) {
            $sanitized[] = NotificationHistory::sanitize($row);
        }

        try {
            $saved = (new NotificationHistory())->saveAll($sanitized, false);
            if (is_countable($saved) && count($saved) === count($sanitized)) {
                $ids = [];
                foreach ($saved as $model) {
                    $ids[] = $model->getKey();
                }
                return $ids;
            }
        } catch (\Throwable $e) {
            error_log('[wise-notification] 批量历史写入失败，降级为逐条写入: ' . $e->getMessage());
        }

        // 降级：逐条写入（保留原有语义与脱敏）
        $ids = [];
        foreach ($rows as $row) {
            try {
                $ids[] = NotificationHistory::record($row)->getKey();
            } catch (\Throwable $e) {
                error_log('[wise-notification] 历史记录写入失败: ' . $e->getMessage());
                $ids[] = null;
            }
        }

        return $ids;
    }

    /**
     * 写入通知审计日志（仅承载策略 / 异常类事件）
     *
     * 设计说明：正常发送链路已由 notification_histories 逐条记录，审计表只记录
     * histories 无法表达为独立动作的事件——批量入队、节流拦截、重试、死信，
     * 避免同一条消息在两张表中重复写库。
     *
     * @param string $action 动作：batch / throttle / retry / dead
     * @param array  $data   审计字段（channel / notifiable_* / message_type / status / error / context）
     */
    private function writeAudit(string $action, array $data): void
    {
        try {
            if (isset($data['context']) && is_array($data['context'])) {
                $data['context'] = NotificationHistory::encodePayload($data['context']);
            }

            NotificationAuditLog::log(array_merge([
                'action'      => $action,
                'status'      => 'pending',
                'create_time' => date('Y-m-d H:i:s'),
            ], $data));
        } catch (\Throwable $e) {
            // 审计失败不得影响主流程
            error_log('[wise-notification] 审计日志写入失败: ' . $e->getMessage());
        }
    }
}
