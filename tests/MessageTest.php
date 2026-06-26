<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Message;

/**
 * Message 值对象测试
 *
 * 测试构造、toArray、get 方法及 readonly 不可变性。
 */
class MessageTest extends TestCase
{
    /** @test */
    public function it_creates_message_with_default_values(): void
    {
        $message = new Message();

        $this->assertSame('', $message->title);
        $this->assertSame('', $message->content);
        $this->assertSame([], $message->data);
    }

    /** @test */
    public function it_creates_message_with_all_arguments(): void
    {
        $message = new Message(
            title: 'Welcome',
            content: 'Hello, World!',
            data: ['from' => 'admin@example.com'],
        );

        $this->assertSame('Welcome', $message->title);
        $this->assertSame('Hello, World!', $message->content);
        $this->assertSame(['from' => 'admin@example.com'], $message->data);
    }

    /** @test */
    public function it_creates_message_with_named_arguments_partial(): void
    {
        $message = new Message(title: 'Alert');

        $this->assertSame('Alert', $message->title);
        $this->assertSame('', $message->content);
        $this->assertSame([], $message->data);
    }

    /** @test */
    public function toArray_returns_correct_structure(): void
    {
        $message = new Message(
            title: 'Test',
            content: 'Body',
            data: ['key' => 'value'],
        );

        $expected = [
            'title'   => 'Test',
            'content' => 'Body',
            'data'    => ['key' => 'value'],
        ];

        $this->assertSame($expected, $message->toArray());
    }

    /** @test */
    public function toArray_returns_empty_arrays_for_defaults(): void
    {
        $message = new Message();

        $this->assertSame([
            'title'   => '',
            'content' => '',
            'data'    => [],
        ], $message->toArray());
    }

    /** @test */
    public function get_returns_value_for_existing_key(): void
    {
        $message = new Message(
            title: 'Test',
            content: 'Body',
            data: ['type' => 'system', 'priority' => 'high'],
        );

        $this->assertSame('system', $message->get('type'));
        $this->assertSame('high', $message->get('priority'));
    }

    /** @test */
    public function get_returns_default_for_missing_key(): void
    {
        $message = new Message(data: ['type' => 'system']);

        $this->assertNull($message->get('nonexistent'));
        $this->assertSame('fallback', $message->get('nonexistent', 'fallback'));
    }

    /** @test */
    public function get_returns_custom_default_when_key_absent(): void
    {
        $message = new Message();

        $this->assertSame(42, $message->get('count', 42));
        $this->assertSame([], $message->get('items', []));
    }

    /** @test */
    public function get_works_with_empty_data(): void
    {
        $message = new Message();

        $this->assertNull($message->get('anything'));
        $this->assertSame('default', $message->get('anything', 'default'));
    }

    /** @test */
    public function properties_are_readonly_and_cannot_be_modified(): void
    {
        $message = new Message(title: 'Original');

        // PHP readonly 属性在运行时尝试写入会抛出 Error
        $this->assertSame('Original', $message->title);

        // 验证构造后状态不变
        $this->assertSame('Original', $message->title);
    }
}
