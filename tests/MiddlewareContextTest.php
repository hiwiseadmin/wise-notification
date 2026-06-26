<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\middleware\SendContext;
use wise\notification\middleware\NotificationMiddleware;
use wise\notification\contract\SendResult;

/**
 * SendContext 值对象测试
 */
class SendContextTest extends TestCase
{
    /** @var Notifiable */
    private Notifiable $notifiable;

    /** @var Message */
    private Message $message;

    protected function setUp(): void
    {
        $this->notifiable = $this->createMock(Notifiable::class);
        $this->message = new Message(title: 'Test', content: 'Body');
    }

    /** @test */
    public function it_creates_context_with_required_properties(): void
    {
        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->assertSame('email', $context->channel);
        $this->assertSame($this->notifiable, $context->notifiable);
        $this->assertSame($this->message, $context->message);
        $this->assertSame([], $context->extra);
    }

    /** @test */
    public function it_creates_context_with_extra_data(): void
    {
        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
            extra: ['trace_id' => 'abc-123'],
        );

        $this->assertSame(['trace_id' => 'abc-123'], $context->extra);
    }

    /** @test */
    public function magic_get_reads_extra_values(): void
    {
        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
            extra: ['trace_id' => 'xyz-789'],
        );

        $this->assertSame('xyz-789', $context->trace_id);
        $this->assertNull($context->nonexistent);
    }

    /** @test */
    public function magic_set_writes_extra_values(): void
    {
        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $context->custom_key = 'custom_value';
        $context->another = 42;

        $this->assertSame('custom_value', $context->custom_key);
        $this->assertSame(42, $context->another);
        $this->assertSame(['custom_key' => 'custom_value', 'another' => 42], $context->extra);
    }

    /** @test */
    public function magic_get_returns_null_for_unset_extra(): void
    {
        $context = new SendContext(
            channel: 'sms',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->assertNull($context->foo);
        $this->assertNull($context->bar);
    }

    /** @test */
    public function toArray_returns_correct_structure(): void
    {
        $context = new SendContext(
            channel: 'database',
            notifiable: $this->notifiable,
            message: $this->message,
            extra: ['key' => 'val'],
        );

        $arr = $context->toArray();

        $this->assertSame('database', $arr['channel']);
        $this->assertSame(get_class($this->notifiable), $arr['notifiable']);
        $this->assertSame($this->message->toArray(), $arr['message']);
        $this->assertSame(['key' => 'val'], $arr['extra']);
    }

    /** @test */
    public function extra_is_mutable(): void
    {
        $context = new SendContext(
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $context->extra['dynamic'] = 'added';
        $this->assertSame('added', $context->extra['dynamic']);

        // Also via magic set
        $context->viaMagic = 'magic';
        $this->assertSame('magic', $context->extra['viaMagic']);
    }
}

/**
 * NotificationMiddleware 中间件管道测试
 */
class NotificationMiddlewareTest extends TestCase
{
    /** @test */
    public function middleware_interface_is_implementable(): void
    {
        $middleware = new class implements NotificationMiddleware {
            public function handle(SendContext $context, callable $next): SendResult
            {
                return $next($context);
            }
        };

        $this->assertInstanceOf(NotificationMiddleware::class, $middleware);
    }

    /** @test */
    public function middleware_can_modify_context_before_passing(): void
    {
        $notifiable = $this->createMock(Notifiable::class);
        $message = new Message(title: 'Test');

        $middleware = new class implements NotificationMiddleware {
            public function handle(SendContext $context, callable $next): SendResult
            {
                $context->modified = true;
                $context->message_title = $context->message->title;
                return $next($context);
            }
        };

        $context = new SendContext(
            channel: 'email',
            notifiable: $notifiable,
            message: $message,
        );

        $expectedResult = new SendResult(success: true, channel: 'email');

        $result = $middleware->handle($context, function (SendContext $ctx) use ($expectedResult) {
            return $expectedResult;
        });

        $this->assertTrue($context->modified);
        $this->assertSame('Test', $context->message_title);
        $this->assertSame($expectedResult, $result);
    }

    /** @test */
    public function middleware_can_short_circuit_and_skip_next(): void
    {
        $notifiable = $this->createMock(Notifiable::class);
        $message = new Message(title: 'Test');

        $blockResult = new SendResult(
            success: false,
            channel: 'email',
            error: 'Blocked by middleware',
        );

        $middleware = new class($blockResult) implements NotificationMiddleware {
            private SendResult $blockResult;

            public function __construct(SendResult $blockResult)
            {
                $this->blockResult = $blockResult;
            }

            public function handle(SendContext $context, callable $next): SendResult
            {
                // 短路：不调用 $next
                return $this->blockResult;
            }
        };

        $context = new SendContext(
            channel: 'email',
            notifiable: $notifiable,
            message: $message,
        );

        $nextCalled = false;
        $result = $middleware->handle($context, function (SendContext $ctx) use (&$nextCalled) {
            $nextCalled = true;
            return new SendResult(success: true, channel: 'email');
        });

        $this->assertFalse($nextCalled, 'Next should NOT be called when middleware short-circuits');
        $this->assertSame($blockResult, $result);
    }
}
