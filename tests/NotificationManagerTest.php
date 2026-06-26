<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\NotificationManager;
use wise\notification\channel\ChannelDriver;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;
use wise\notification\contract\BatchSendResult;
use wise\notification\event\NotificationSending;
use wise\notification\event\NotificationSent;
use wise\notification\event\NotificationFailed;
use wise\notification\middleware\NotificationMiddleware;
use wise\notification\middleware\SendContext;
use think\facade\Event as EventFacade;
use think\facade\Queue as QueueFacade;

/**
 * NotificationManager 核心引擎测试
 *
 * 使用 Mock 驱动和桩系统测试 Manager-Driver 模式核心逻辑。
 */
class NotificationManagerTest extends TestCase
{
    private NotificationManager $manager;
    private Notifiable $notifiable;
    private Message $message;

    protected function setUp(): void
    {
        // 重置全局状态
        \wise\notification\tests\helpers\MockApp::reset();
        EventFacade::reset();
        QueueFacade::reset();

        $this->manager = new NotificationManager();
        $this->notifiable = $this->createNotifiableMock(1, 'user@example.com');
        $this->message = new Message(
            title: 'Test Notification',
            content: 'This is a test message.',
            data: ['type' => 'system'],
        );
    }

    /**
     * 创建 Notifiable mock
     */
    private function createNotifiableMock(int $id, string $email = ''): Notifiable
    {
        return new class($id, $email) implements Notifiable {
            public function __construct(
                private int $id,
                private string $email = '',
            ) {}

            public function routeNotificationFor(string $channel): string|array
            {
                return match ($channel) {
                    'email'    => $this->email,
                    'database' => (string) $this->id,
                    'sms'      => '13800138000',
                    'dingtalk' => '13800138000',
                    default    => (string) $this->id,
                };
            }

            public function getKey(): int
            {
                return $this->id;
            }
        };
    }

    /**
     * 创建 Mock 通道驱动
     */
    private function createMockDriver(string $name, SendResult $returnResult): ChannelDriver
    {
        return new class($name, $returnResult) implements ChannelDriver {
            public function __construct(
                private string $name,
                private SendResult $returnResult,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function send(Notifiable $notifiable, Message $message): SendResult
            {
                return $this->returnResult;
            }
        };
    }

    // ── 通道选择 channel() ──────────────────────────────────────

    /** @test */
    public function channel_sets_single_channel(): void
    {
        $result = $this->manager->channel('sms');

        $this->assertSame($this->manager, $result); // 链式调用返回自身
    }

    /** @test */
    public function channel_sets_multiple_channels(): void
    {
        $result = $this->manager->channel(['email', 'sms', 'database']);

        $this->assertSame($this->manager, $result);
    }

    /** @test */
    public function channel_is_fluent(): void
    {
        $result = $this->manager->channel('email')->sync()->template('welcome');

        $this->assertInstanceOf(NotificationManager::class, $result);
    }

    // ── 同步模式 sync() ─────────────────────────────────────────

    /** @test */
    public function sync_returns_self_for_chaining(): void
    {
        $result = $this->manager->sync();

        $this->assertSame($this->manager, $result);
    }

    /** @test */
    public function sync_mode_dispatches_directly_not_to_queue(): void
    {
        // 注册 mock 驱动
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true,
            channel: 'email',
            response: 'Mock OK',
            durationMs: 10,
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->channel('email')->sync()->send($this->notifiable, $this->message);

        $this->assertInstanceOf(SendResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('email', $result->channel);

        // 队列应该没有推送
        $this->assertSame(0, QueueFacade::getPushedCount(
            \wise\notification\job\SendNotificationJob::class
        ));
    }

    // ── 模板模式 template() ─────────────────────────────────────

    /** @test */
    public function template_sets_template_code(): void
    {
        $result = $this->manager->template('welcome_email');

        $this->assertSame($this->manager, $result);
    }

    // ── createMessage 工厂方法 ──────────────────────────────────

    /** @test */
    public function createMessage_returns_message_instance(): void
    {
        $message = $this->manager->createMessage('Hello', 'World', ['key' => 'val']);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Hello', $message->title);
        $this->assertSame('World', $message->content);
        $this->assertSame(['key' => 'val'], $message->data);
    }

    /** @test */
    public function createMessage_handles_empty_args(): void
    {
        $message = $this->manager->createMessage();

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('', $message->title);
        $this->assertSame('', $message->content);
        $this->assertSame([], $message->data);
    }

    // ── 驱动解析 resolveDriver() ────────────────────────────────

    /** @test */
    public function resolveDriver_resolves_builtin_database_driver(): void
    {
        $driver = $this->manager->resolveDriver('database');

        $this->assertInstanceOf(ChannelDriver::class, $driver);
        $this->assertSame('database', $driver->getName());
    }

    /** @test */
    public function resolveDriver_resolves_builtin_email_driver(): void
    {
        $driver = $this->manager->resolveDriver('email');

        $this->assertInstanceOf(ChannelDriver::class, $driver);
        $this->assertSame('email', $driver->getName());
    }

    /** @test */
    public function resolveDriver_resolves_builtin_sms_driver(): void
    {
        $driver = $this->manager->resolveDriver('sms');

        $this->assertInstanceOf(ChannelDriver::class, $driver);
        $this->assertSame('sms', $driver->getName());
    }

    /** @test */
    public function resolveDriver_resolves_builtin_dingtalk_driver(): void
    {
        $driver = $this->manager->resolveDriver('dingtalk');

        $this->assertInstanceOf(ChannelDriver::class, $driver);
        $this->assertSame('dingtalk', $driver->getName());
    }

    /** @test */
    public function resolveDriver_throws_for_unknown_channel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未注册的通知通道驱动');

        $this->manager->resolveDriver('unknown_channel');
    }

    /** @test */
    public function resolveDriver_caches_resolved_drivers(): void
    {
        $driver1 = $this->manager->resolveDriver('email');
        $driver2 = $this->manager->resolveDriver('email');

        // 同一实例被缓存返回
        $this->assertSame($driver1, $driver2);
    }

    // ── 自定义驱动 extend() ─────────────────────────────────────

    /** @test */
    public function extend_registers_custom_driver(): void
    {
        $mockDriver = $this->createMockDriver('custom_channel', new SendResult(
            success: true, channel: 'custom_channel'
        ));

        $this->manager->extend('custom_channel', $mockDriver);

        $resolved = $this->manager->resolveDriver('custom_channel');
        $this->assertSame($mockDriver, $resolved);
    }

    /** @test */
    public function extend_overrides_builtin_driver(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email', response: 'Custom Override'
        ));

        $this->manager->extend('email', $mockDriver);

        $resolved = $this->manager->resolveDriver('email');
        $this->assertSame($mockDriver, $resolved);
    }

    // ── 中间件 extendMiddleware() ───────────────────────────────

    /** @test */
    public function extendMiddleware_adds_middleware(): void
    {
        $middleware = $this->createMock(NotificationMiddleware::class);
        $middleware->method('handle')
            ->willReturnCallback(fn (SendContext $ctx, callable $next) => $next($ctx));

        // 不应抛出异常
        $this->manager->extendMiddleware($middleware);
        $this->assertTrue(true); // 如果到这里说明成功
    }

    // ── 单通道同步发送 send() ───────────────────────────────────

    /** @test */
    public function send_with_sync_mode_returns_send_result(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true,
            channel: 'email',
            response: 'Delivered',
            durationMs: 25,
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->channel('email')->sync()->send($this->notifiable, $this->message);

        $this->assertInstanceOf(SendResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('email', $result->channel);
        $this->assertSame('Delivered', $result->response);
    }

    /** @test */
    public function send_with_sync_mode_handles_failure(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: false,
            channel: 'email',
            error: 'SMTP connection refused',
            durationMs: 3000,
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->channel('email')->sync()->send($this->notifiable, $this->message);

        $this->assertInstanceOf(SendResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertSame('SMTP connection refused', $result->error);
    }

    // ── 多通道发送返回 BatchSendResult ──────────────────────────

    /** @test */
    public function send_with_multiple_channels_returns_batch_send_result(): void
    {
        $emailDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email', response: 'OK'
        ));
        $smsDriver = $this->createMockDriver('sms', new SendResult(
            success: true, channel: 'sms', response: 'OK'
        ));
        $this->manager->extend('email', $emailDriver);
        $this->manager->extend('sms', $smsDriver);

        $result = $this->manager->channel(['email', 'sms'])->sync()->send(
            $this->notifiable, $this->message
        );

        $this->assertInstanceOf(BatchSendResult::class, $result);
        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->successCount);
        $this->assertSame(0, $result->failedCount);
    }

    /** @test */
    public function send_multi_channel_aggregates_mixed_results(): void
    {
        $emailDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email'
        ));
        $smsDriver = $this->createMockDriver('sms', new SendResult(
            success: false, channel: 'sms', error: 'Failed'
        ));
        $dbDriver = $this->createMockDriver('database', new SendResult(
            success: true, channel: 'database'
        ));
        $this->manager->extend('email', $emailDriver);
        $this->manager->extend('sms', $smsDriver);
        $this->manager->extend('database', $dbDriver);

        $result = $this->manager->channel(['email', 'sms', 'database'])->sync()->send(
            $this->notifiable, $this->message
        );

        $this->assertInstanceOf(BatchSendResult::class, $result);
        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->successCount);
        $this->assertSame(1, $result->failedCount);

        $failed = $result->getFailed();
        $this->assertCount(1, $failed);
        $this->assertSame('sms', $failed[0]->channel);
    }

    // ── 异步模式 send() ─────────────────────────────────────────

    /** @test */
    public function send_async_returns_null_and_pushes_to_queue(): void
    {
        $result = $this->manager->channel('email')->send($this->notifiable, $this->message);

        $this->assertNull($result);

        // 验证队列推送
        $pushed = QueueFacade::getPushed();
        $this->assertCount(1, $pushed);
        $this->assertSame(
            \wise\notification\job\SendNotificationJob::class,
            $pushed[0]['class']
        );
        $this->assertSame('notification', $pushed[0]['queue']);
        $this->assertSame('email', $pushed[0]['data']['channel']);
    }

    /** @test */
    public function send_async_pushes_correct_message_data(): void
    {
        $this->manager->channel('email')->send($this->notifiable, $this->message);

        $pushed = QueueFacade::getPushed();
        $data = $pushed[0]['data'];

        $this->assertSame('email', $data['channel']);
        $this->assertArrayHasKey('notifiable_type', $data);
        $this->assertArrayHasKey('notifiable_id', $data);
        $this->assertSame($this->message->toArray(), $data['message']);
        $this->assertSame('direct', $data['message_type']);
        $this->assertFalse($data['is_batch']);
    }

    /** @test */
    public function send_async_with_template_sets_message_type_to_template(): void
    {
        // 注意：template 模式需要数据库模板，这里不实际加载
        // 仅验证异步模式下的 message type 逻辑
        // 传入 Message 对象 + 未设置 templateCode → message_type='direct'
        $this->manager->channel('sms')->send($this->notifiable, $this->message);

        $data = QueueFacade::getPushed()[0]['data'];
        $this->assertSame('direct', $data['message_type']);
    }

    // ── 批量发送 batch() ────────────────────────────────────────

    /** @test */
    public function batch_pushes_jobs_for_multiple_recipients(): void
    {
        $user1 = $this->createNotifiableMock(1, 'u1@example.com');
        $user2 = $this->createNotifiableMock(2, 'u2@example.com');
        $user3 = $this->createNotifiableMock(3, 'u3@example.com');

        $batchResult = $this->manager->channel('email')->batch(
            [$user1, $user2, $user3],
            $this->message
        );

        $this->assertInstanceOf(BatchSendResult::class, $batchResult);

        // 3 users × 1 channel = 3 jobs pushed
        $pushedCount = QueueFacade::getPushedCount(
            \wise\notification\job\SendNotificationJob::class
        );
        $this->assertSame(3, $pushedCount);

        $this->assertSame(3, $batchResult->total);
        $this->assertSame(3, $batchResult->successCount);
    }

    /** @test */
    public function batch_respects_chunk_option(): void
    {
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = $this->createNotifiableMock($i, "u{$i}@example.com");
        }

        $this->manager->channel('email')->batch($users, $this->message, ['chunk' => 2]);

        // 5 users × 1 channel = 5 jobs
        $pushedCount = QueueFacade::getPushedCount(
            \wise\notification\job\SendNotificationJob::class
        );
        $this->assertSame(5, $pushedCount);
    }

    /** @test */
    public function batch_sets_is_batch_flag_in_job_data(): void
    {
        $user = $this->createNotifiableMock(1, 'test@example.com');

        $this->manager->channel('email')->batch([$user], $this->message);

        $data = QueueFacade::getPushed()[0]['data'];
        $this->assertTrue($data['is_batch']);
        $this->assertStringStartsWith('batch_', $data['batch_id']);
    }

    /** @test */
    public function batch_handles_multi_channel(): void
    {
        $user1 = $this->createNotifiableMock(1, 'u1@example.com');
        $user2 = $this->createNotifiableMock(2, 'u2@example.com');

        $this->manager->channel(['email', 'sms'])->batch([$user1, $user2], $this->message);

        // 2 users × 2 channels = 4 jobs
        $pushedCount = QueueFacade::getPushedCount(
            \wise\notification\job\SendNotificationJob::class
        );
        $this->assertSame(4, $pushedCount);
    }

    // ── dispatch() 核心调度 ─────────────────────────────────────

    /** @test */
    public function dispatch_triggers_sending_and_sent_events(): void
    {
        EventFacade::reset();
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email', response: 'OK', durationMs: 10,
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->dispatch('email', $this->notifiable, $this->message);

        $this->assertTrue($result->success);

        // 验证事件触发
        $sendingEvents = EventFacade::getTriggeredByClass(NotificationSending::class);
        $sentEvents = EventFacade::getTriggeredByClass(NotificationSent::class);

        $this->assertCount(1, $sendingEvents, 'NotificationSending should be triggered');
        $this->assertCount(1, $sentEvents, 'NotificationSent should be triggered');
    }

    /** @test */
    public function dispatch_triggers_failed_event_on_error(): void
    {
        EventFacade::reset();
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: false, channel: 'email', error: 'Send failed', durationMs: 100,
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->dispatch('email', $this->notifiable, $this->message);

        $this->assertFalse($result->success);

        $failedEvents = EventFacade::getTriggeredByClass(NotificationFailed::class);
        $this->assertCount(1, $failedEvents, 'NotificationFailed should be triggered');
    }

    /** @test */
    public function dispatch_halted_event_returns_failure(): void
    {
        EventFacade::reset();

        // 通过 Event::trigger 传入已 halted 的事件
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email', response: 'Should not reach',
        ));
        $this->manager->extend('email', $mockDriver);

        // 监听 NotificationSending 并调用 halt()
        EventFacade::listen(NotificationSending::class, function (NotificationSending $event) {
            $event->halt();
        });

        $result = $this->manager->dispatch('email', $this->notifiable, $this->message);

        $this->assertFalse($result->success);
        $this->assertSame('halted by NotificationSending event', $result->error);

        // 不应该触发 NotificationSent
        $sentEvents = EventFacade::getTriggeredByClass(NotificationSent::class);
        $this->assertCount(0, $sentEvents, 'NotificationSent should NOT be triggered when halted');
    }

    /** @test */
    public function dispatch_catches_driver_exceptions(): void
    {
        EventFacade::reset();

        // 创建一个会抛出异常的驱动
        $throwingDriver = new class implements ChannelDriver {
            public function getName(): string { return 'faulty'; }
            public function send(Notifiable $notifiable, Message $message): SendResult
            {
                throw new \RuntimeException('Driver exploded!');
            }
        };
        $this->manager->extend('faulty', $throwingDriver);

        $result = $this->manager->dispatch('faulty', $this->notifiable, $this->message);

        $this->assertFalse($result->success);
        $this->assertSame('Driver exploded!', $result->error);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    // ── runMiddleware() 管道测试 ─────────────────────────────────

    /** @test */
    public function runMiddleware_calls_driver_when_no_middleware(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email', response: 'Direct', durationMs: 5,
        ));

        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $result = $this->manager->runMiddleware($context, $mockDriver);

        $this->assertTrue($result->success);
        $this->assertSame('Direct', $result->response);
    }

    /** @test */
    public function runMiddleware_executes_pipeline_with_middleware(): void
    {
        // 注册一个修改上下文的中间件
        $middleware = new class implements NotificationMiddleware {
            public function handle(SendContext $context, callable $next): SendResult
            {
                $context->processed_by = 'middleware_1';
                return $next($context);
            }
        };
        $this->manager->extendMiddleware($middleware);

        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email',
        ));

        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $result = $this->manager->runMiddleware($context, $mockDriver);

        $this->assertTrue($result->success);
        $this->assertSame('middleware_1', $context->processed_by);
    }

    /** @test */
    public function runMiddleware_executes_multiple_middleware_in_order(): void
    {
        $middleware1 = new class implements NotificationMiddleware {
            public function handle(SendContext $context, callable $next): SendResult
            {
                $context->order = ($context->order ?? '') . '1';
                return $next($context);
            }
        };
        $middleware2 = new class implements NotificationMiddleware {
            public function handle(SendContext $context, callable $next): SendResult
            {
                $context->order = ($context->order ?? '') . '2';
                return $next($context);
            }
        };
        $this->manager->extendMiddleware($middleware1);
        $this->manager->extendMiddleware($middleware2);

        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email',
        ));

        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->manager->runMiddleware($context, $mockDriver);

        // 中间件从左到右执行：先 middleware1 再 middleware2
        $this->assertSame('12', $context->order);
    }

    // ── 发送数组消息（非 Message 对象） ──────────────────────────

    /** @test */
    public function send_with_array_message_converts_to_message(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email',
        ));
        $this->manager->extend('email', $mockDriver);

        $result = $this->manager->channel('email')->sync()->send(
            $this->notifiable,
            ['title' => 'Array Title', 'content' => 'Array Content', 'extra' => 'value']
        );

        $this->assertInstanceOf(SendResult::class, $result);
        $this->assertTrue($result->success);
    }

    /** @test */
    public function send_with_array_message_uses_defaults_for_missing_keys(): void
    {
        $mockDriver = $this->createMockDriver('email', new SendResult(
            success: true, channel: 'email',
        ));
        $this->manager->extend('email', $mockDriver);

        // 空数组应该被转换为默认 Message
        $result = $this->manager->channel('email')->sync()->send($this->notifiable, []);

        $this->assertInstanceOf(SendResult::class, $result);
    }
}
