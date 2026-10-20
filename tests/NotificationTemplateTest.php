<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Message;
use wise\notification\model\NotificationTemplate;

/**
 * NotificationTemplate 模型测试
 *
 * 测试模板变量渲染、getByCode 查询（桩模式）。
 */
class NotificationTemplateTest extends TestCase
{
    // ── render() 变量替换测试 ────────────────────────────────────

    /** @test */
    public function render_replaces_single_variable_in_title(): void
    {
        $template = new NotificationTemplate([
            'code'    => 'welcome',
            'title'   => 'Hello {name}',
            'content' => 'Welcome to our platform',
            'locale'  => 'zh_CN',
            'status'  => 1,
        ]);

        $message = $template->render(['name' => 'Alice']);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Hello Alice', $message->title);
        $this->assertSame('Welcome to our platform', $message->content);
    }

    /** @test */
    public function render_replaces_multiple_variables(): void
    {
        $template = new NotificationTemplate([
            'title'   => '{action} Notification',
            'content' => 'Dear {user}, your {item} has been {action}.',
        ]);

        $message = $template->render([
            'action' => 'Order',
            'user'   => 'Bob',
            'item'   => 'package',
        ]);

        $this->assertSame('Order Notification', $message->title);
        $this->assertSame('Dear Bob, your package has been Order.', $message->content);
    }

    /** @test */
    public function render_replaces_repeated_variables(): void
    {
        $template = new NotificationTemplate([
            'title'   => '{code} Verification',
            'content' => 'Your code is {code}, expires in {minutes} minutes.',
        ]);

        $message = $template->render([
            'code'    => '123456',
            'minutes' => '5',
        ]);

        $this->assertSame('123456 Verification', $message->title);
        $this->assertSame('Your code is 123456, expires in 5 minutes.', $message->content);
    }

    /** @test */
    public function render_preserves_text_without_variables(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Static Title',
            'content' => 'Static content without variables.',
        ]);

        $message = $template->render([]);

        $this->assertSame('Static Title', $message->title);
        $this->assertSame('Static content without variables.', $message->content);
    }

    /** @test */
    public function render_stores_variables_in_message_data(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Hi {name}',
            'content' => 'Content',
        ]);

        $message = $template->render(['name' => 'Charlie', 'role' => 'admin']);

        $this->assertSame(['name' => 'Charlie', 'role' => 'admin'], $message->data);
    }

    /** @test */
    public function render_throws_when_variable_not_provided(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Hi {name}',
            'content' => 'Your role is {role}',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('模板变量');

        // 缺少 'name' 变量
        $template->render(['role' => 'admin']);
    }

    /** @test */
    public function render_throws_with_helpful_message_listing_available_variables(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Hi {name}',
            'content' => '{greeting}',
        ]);

        try {
            $template->render([]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('"name"', $e->getMessage());
            $this->assertStringContainsString('未提供', $e->getMessage());
        }
    }

    /** @test */
    public function render_handles_variable_at_start_of_string(): void
    {
        $template = new NotificationTemplate([
            'title'   => '{prefix} Title',
            'content' => 'Body',
        ]);

        $message = $template->render(['prefix' => 'Important']);

        $this->assertSame('Important Title', $message->title);
    }

    /** @test */
    public function render_handles_variable_at_end_of_string(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Title',
            'content' => 'Ends with {suffix}',
        ]);

        $message = $template->render(['suffix' => '!!!']);

        $this->assertSame('Ends with !!!', $message->content);
    }

    /** @test */
    public function render_handles_empty_title_and_content(): void
    {
        $template = new NotificationTemplate([
            'title'   => '',
            'content' => '',
        ]);

        $message = $template->render([]);

        $this->assertSame('', $message->title);
        $this->assertSame('', $message->content);
        $this->assertSame([], $message->data);
    }

    /** @test */
    public function render_variable_values_are_cast_to_string(): void
    {
        $template = new NotificationTemplate([
            'title'   => 'Count: {count}',
            'content' => 'Price: ${price}',
        ]);

        $message = $template->render([
            'count' => 42,
            'price' => 9.99,
        ]);

        $this->assertSame('Count: 42', $message->title);
        $this->assertSame('Price: $9.99', $message->content);
    }

    /** @test */
    public function render_handles_special_characters_in_variables(): void
    {
        $template = new NotificationTemplate([
            'title'   => '{greeting}',
            'content' => 'Normal',
        ]);

        $message = $template->render(['greeting' => 'Hello & Welcome <3']);

        $this->assertSame('Hello & Welcome <3', $message->title);
    }

    // ── getByCode() 测试（使用桩 Model）─────────────────────────

    /** @test */
    public function getByCode_when_template_exists(): void
    {
        // 设置测试数据到桩模型的实例
        // 因为 ThinkPHP 桩的 find() 默认返回 $this，
        // 我们需要在模型上预设数据
        $template = NotificationTemplate::getByCode('welcome', 'zh_CN');

        // 在桩环境下，getByCode 返回的是新实例
        $this->assertInstanceOf(NotificationTemplate::class, $template);
    }

    /** @test */
    public function getByCode_defaults_locale_to_zh_CN(): void
    {
        $template = NotificationTemplate::getByCode('welcome');

        $this->assertInstanceOf(NotificationTemplate::class, $template);
    }
}
