<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\channel\ChannelDriver;
use wise\notification\channel\EmailChannel;
use wise\notification\channel\SmsChannel;
use wise\notification\channel\DingtalkChannel;
use wise\notification\channel\DatabaseChannel;

/**
 * 通道驱动测试
 *
 * 测试各通道的实例化和 getName() 方法。
 * 对于依赖外部服务的 send() 方法，验证类结构但不执行实际发送。
 */
class ChannelDriverTest extends TestCase
{
    // ── EmailChannel ─────────────────────────────────────────────

    /** @test */
    public function email_channel_implements_channel_driver(): void
    {
        $channel = new EmailChannel();
        $this->assertInstanceOf(ChannelDriver::class, $channel);
    }

    /** @test */
    public function email_channel_getName_returns_email(): void
    {
        $channel = new EmailChannel();
        $this->assertSame('email', $channel->getName());
    }

    // ── SmsChannel ───────────────────────────────────────────────

    /** @test */
    public function sms_channel_implements_channel_driver(): void
    {
        $channel = new SmsChannel();
        $this->assertInstanceOf(ChannelDriver::class, $channel);
    }

    /** @test */
    public function sms_channel_getName_returns_sms(): void
    {
        $channel = new SmsChannel();
        $this->assertSame('sms', $channel->getName());
    }

    // ── DingtalkChannel ──────────────────────────────────────────

    /** @test */
    public function dingtalk_channel_implements_channel_driver(): void
    {
        $channel = new DingtalkChannel();
        $this->assertInstanceOf(ChannelDriver::class, $channel);
    }

    /** @test */
    public function dingtalk_channel_getName_returns_dingtalk(): void
    {
        $channel = new DingtalkChannel();
        $this->assertSame('dingtalk', $channel->getName());
    }

    // ── DatabaseChannel ──────────────────────────────────────────

    /** @test */
    public function database_channel_implements_channel_driver(): void
    {
        $channel = new DatabaseChannel();
        $this->assertInstanceOf(ChannelDriver::class, $channel);
    }

    /** @test */
    public function database_channel_getName_returns_database(): void
    {
        $channel = new DatabaseChannel();
        $this->assertSame('database', $channel->getName());
    }
}
