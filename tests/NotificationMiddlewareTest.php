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
