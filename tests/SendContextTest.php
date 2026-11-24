<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\middleware\SendContext;

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

        $context->viaMagic = 'magic';
        $this->assertSame('magic', $context->extra['viaMagic']);
    }
}
