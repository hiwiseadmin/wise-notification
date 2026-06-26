<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\model\NotificationHistory;
use wise\notification\model\UserNotification;

/**
 * 模型测试（NotificationHistory & UserNotification）
 *
 * 在桩 think\Model 环境中测试模型的静态方法和实例方法。
 */
class ModelsTest extends TestCase
{
    // ── NotificationHistory::record() ────────────────────────────

    /** @test */
    public function notification_history_record_creates_and_saves(): void
    {
        $data = [
            'channel'         => 'email',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => '1',
            'message_type'    => 'direct',
            'message_payload' => '{"title":"Test"}',
            'status'          => 'pending',
            'create_time'      => date('Y-m-d H:i:s'),
        ];

        $record = NotificationHistory::record($data);

        $this->assertInstanceOf(NotificationHistory::class, $record);

        // 桩 Model 的 save() 会将数据保存到 data 属性
        $this->assertSame('email', $record->getAttr('channel'));
        $this->assertSame('App\\Models\\User', $record->getAttr('notifiable_type'));
        $this->assertSame('1', $record->getAttr('notifiable_id'));
        $this->assertSame('pending', $record->getAttr('status'));
    }

    /** @test */
    public function notification_history_record_stores_all_fields(): void
    {
        $data = [
            'channel'         => 'sms',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => '42',
            'message_type'    => 'template',
            'message_payload' => '{"title":"Alert","content":"Something happened"}',
            'status'          => 'sent',
            'response'        => '{"code":0}',
            'duration_ms'     => 250,
            'create_time'      => '2026-06-13 10:00:00',
        ];

        $record = NotificationHistory::record($data);

        $this->assertSame('sms', $record->getAttr('channel'));
        $this->assertSame('42', $record->getAttr('notifiable_id'));
        $this->assertSame('sent', $record->getAttr('status'));
        $this->assertSame('{"code":0}', $record->getAttr('response'));
        $this->assertSame(250, $record->getAttr('duration_ms'));
    }

    /** @test */
    public function notification_history_record_with_minimal_data(): void
    {
        $record = NotificationHistory::record([
            'channel' => 'database',
            'status'  => 'pending',
        ]);

        $this->assertInstanceOf(NotificationHistory::class, $record);
        $this->assertSame('database', $record->getAttr('channel'));
        $this->assertSame('pending', $record->getAttr('status'));
    }

    /** @test */
    public function notification_history_record_with_failed_status(): void
    {
        $record = NotificationHistory::record([
            'channel'         => 'dingtalk',
            'notifiable_type' => 'User',
            'notifiable_id'   => '99',
            'message_type'    => 'direct',
            'message_payload' => '[]',
            'status'          => 'failed',
            'error'           => 'Webhook timeout',
            'duration_ms'     => 30000,
            'create_time'      => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('failed', $record->getAttr('status'));
        $this->assertSame('Webhook timeout', $record->getAttr('error'));
    }

    // ── UserNotification::markAsRead() ──────────────────────────

    /** @test */
    public function user_notification_markAsRead_sets_is_read_and_read_at(): void
    {
        $notification = new UserNotification([
            'id'      => 1,
            'user_id' => 10,
            'title'   => 'Test',
            'content' => 'Body',
            'is_read' => 0,
        ]);

        $result = $notification->markAsRead();

        $this->assertTrue($result);
        $this->assertSame(1, $notification->getAttr('is_read'));
        $this->assertNotNull($notification->getAttr('read_at'));
        // read_at 应该是近期的日期时间
        $readAt = $notification->getAttr('read_at');
        $this->assertStringContainsString(date('Y-m-d'), $readAt);
    }

    /** @test */
    public function user_notification_markAsRead_on_already_read(): void
    {
        $notification = new UserNotification([
            'id'      => 2,
            'user_id' => 20,
            'title'   => 'Already Read',
            'is_read' => 1,
            'read_at' => '2026-01-01 00:00:00',
        ]);

        $result = $notification->markAsRead();

        $this->assertTrue($result);
        $this->assertSame(1, $notification->getAttr('is_read'));
        // read_at 应被更新为当前时间
        $this->assertNotSame('2026-01-01 00:00:00', $notification->getAttr('read_at'));
    }

    /** @test */
    public function user_notification_initial_state_is_unread(): void
    {
        $notification = new UserNotification([
            'title'   => 'New Notification',
            'content' => 'You have a message',
            'is_read' => 0,
        ]);

        $this->assertSame(0, $notification->getAttr('is_read'));
        $this->assertNull($notification->getAttr('read_at'));
    }

    /** @test */
    public function user_notification_save_preserves_data(): void
    {
        $notification = new UserNotification([
            'user_id' => 5,
            'title'   => 'System Alert',
            'content' => 'Maintenance at midnight',
            'type'    => 'system',
            'is_read' => 0,
        ]);

        $notification->save([
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('System Alert', $notification->getAttr('title'));
        $this->assertSame('system', $notification->getAttr('type'));
        $this->assertNotNull($notification->getAttr('create_time'));
    }
}
