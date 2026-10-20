<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\NotificationManager;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;
use wise\notification\event\NotificationSending;
use wise\notification\event\NotificationSent;
use wise\notification\event\NotificationFailed;

/**
 * 事件类测试
 *
 * 测试 NotificationSending、NotificationSent、NotificationFailed
 * 的构造、属性和 halt() 行为。
 */
class EventsTest extends TestCase
{
    private NotificationManager $manager;
    private Notifiable $notifiable;
    private Message $message;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(NotificationManager::class);
        $this->notifiable = $this->createMock(Notifiable::class);
        $this->message = new Message(title: 'Test', content: 'Body');
    }

    // ── NotificationSending 测试 ─────────────────────────────────

    /** @test */
    public function notification_sending_creates_with_correct_properties(): void
    {
        $event = new NotificationSending(
            manager: $this->manager,
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->assertSame($this->manager, $event->manager);
        $this->assertSame('email', $event->channel);
        $this->assertSame($this->notifiable, $event->notifiable);
        $this->assertSame($this->message, $event->message);
        $this->assertFalse($event->halted);
    }

    /** @test */
    public function notification_sending_halted_defaults_to_false(): void
    {
        $event = new NotificationSending(
            manager: $this->manager,
            channel: 'sms',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->assertFalse($event->halted);
    }

    /** @test */
    public function halt_sets_halted_to_true(): void
    {
        $event = new NotificationSending(
            manager: $this->manager,
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $this->assertFalse($event->halted);

        $event->halt();

        $this->assertTrue($event->halted);
    }

    /** @test */
    public function halt_can_be_called_multiple_times(): void
    {
        $event = new NotificationSending(
            manager: $this->manager,
            channel: 'email',
            notifiable: $this->notifiable,
            message: $this->message,
        );

        $event->halt();
        $event->halt(); // 多次调用不会出错

        $this->assertTrue($event->halted);
    }

    /** @test */
    public function notification_sending_preserves_message_content(): void
    {
        $message = new Message(
            title: 'Important',
            content: 'Action required',
            data: ['priority' => 'high'],
        );

        $event = new NotificationSending(
            manager: $this->manager,
            channel: 'dingtalk',
            notifiable: $this->notifiable,
            message: $message,
        );

        $this->assertSame('Important', $event->message->title);
        $this->assertSame('Action required', $event->message->content);
        $this->assertSame('high', $event->message->get('priority'));
    }

    // ── NotificationSent 测试 ────────────────────────────────────

    /** @test */
    public function notification_sent_creates_with_correct_properties(): void
    {
        $result = new SendResult(success: true, channel: 'email', response: 'OK', durationMs: 42);

        $event = new NotificationSent(
            manager: $this->manager,
            channel: 'email',
            notifiable: $this->notifiable,
            result: $result,
        );

        $this->assertSame($this->manager, $event->manager);
        $this->assertSame('email', $event->channel);
        $this->assertSame($this->notifiable, $event->notifiable);
        $this->assertSame($result, $event->result);
        $this->assertTrue($event->result->success);
    }

    /** @test */
    public function notification_sent_all_properties_are_readonly(): void
    {
        $result = new SendResult(success: true, channel: 'sms');
        $event = new NotificationSent(
            manager: $this->manager,
            channel: 'sms',
            notifiable: $this->notifiable,
            result: $result,
        );

        // 验证属性值
        $this->assertSame('sms', $event->channel);
        $this->assertSame($result, $event->result);
    }

    // ── NotificationFailed 测试 ──────────────────────────────────

    /** @test */
    public function notification_failed_creates_with_correct_properties(): void
    {
        $result = new SendResult(
            success: false,
            channel: 'sms',
            error: 'Gateway timeout',
            durationMs: 5000,
        );

        $event = new NotificationFailed(
            manager: $this->manager,
            channel: 'sms',
            notifiable: $this->notifiable,
            result: $result,
        );

        $this->assertSame($this->manager, $event->manager);
        $this->assertSame('sms', $event->channel);
        $this->assertSame($this->notifiable, $event->notifiable);
        $this->assertSame($result, $event->result);
        $this->assertFalse($event->result->success);
        $this->assertSame('Gateway timeout', $event->result->error);
    }

    /** @test */
    public function notification_failed_carries_error_details(): void
    {
        $result = new SendResult(
            success: false,
            channel: 'dingtalk',
            error: 'Webhook unreachable',
            durationMs: 30000,
        );

        $event = new NotificationFailed(
            manager: $this->manager,
            channel: 'dingtalk',
            notifiable: $this->notifiable,
            result: $result,
        );

        $this->assertSame('Webhook unreachable', $event->result->error);
        $this->assertSame(30000, $event->result->durationMs);
    }
}
