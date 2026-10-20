<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Notifiable;
use wise\notification\traits\NotifiableTrait;

/**
 * NotifiableTrait 测试
 *
 * 测试路由逻辑：各通道路由到模型的正确属性。
 */
class NotifiableTraitTest extends TestCase
{
    /**
     * 测试用 Notifiable 模型
     */
    private function createNotifiableModel(array $attributes = []): object
    {
        return new class($attributes) implements Notifiable {
            use NotifiableTrait;

            public function __construct(private array $attrs) {}

            public function __get(string $name): mixed
            {
                return $this->attrs[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->attrs[$name]);
            }

            public function getKey()
            {
                return $this->attrs['id'] ?? 0;
            }
        };
    }

    /** @test */
    public function routeNotificationFor_email_returns_email_attribute(): void
    {
        $user = $this->createNotifiableModel([
            'id'    => 1,
            'email' => 'user@example.com',
        ]);

        $this->assertSame('user@example.com', $user->routeNotificationFor('email'));
    }

    /** @test */
    public function routeNotificationFor_sms_returns_phone_attribute(): void
    {
        $user = $this->createNotifiableModel([
            'id'    => 2,
            'phone' => '13800138000',
        ]);

        $this->assertSame('13800138000', $user->routeNotificationFor('sms'));
    }

    /** @test */
    public function routeNotificationFor_database_returns_id_attribute(): void
    {
        $user = $this->createNotifiableModel([
            'id' => 42,
        ]);

        $this->assertSame('42', $user->routeNotificationFor('database'));
    }

    /** @test */
    public function routeNotificationFor_dingtalk_returns_phone_attribute(): void
    {
        $user = $this->createNotifiableModel([
            'id'    => 3,
            'phone' => '13900139000',
        ]);

        $this->assertSame('13900139000', $user->routeNotificationFor('dingtalk'));
    }

    /** @test */
    public function routeNotificationFor_unknown_channel_falls_back_to_id(): void
    {
        $user = $this->createNotifiableModel([
            'id' => 99,
        ]);

        // 未映射的通道默认走 'id' 属性
        $this->assertSame('99', $user->routeNotificationFor('unknown_channel'));
    }

    /** @test */
    public function routeNotificationFor_returns_empty_string_when_attribute_missing(): void
    {
        $user = $this->createNotifiableModel([
            'id' => 1,
            // email 属性不存在
        ]);

        $this->assertSame('', $user->routeNotificationFor('email'));
    }

    /** @test */
    public function routeNotificationFor_returns_empty_string_when_attribute_null(): void
    {
        $user = $this->createNotifiableModel([
            'id'    => 1,
            'email' => null,
        ]);

        $this->assertSame('', $user->routeNotificationFor('email'));
    }

    /** @test */
    public function routeNotificationFor_casts_to_string(): void
    {
        $user = $this->createNotifiableModel([
            'id'    => 100,
            'phone' => 13800138000, // 整数
        ]);

        $result = $user->routeNotificationFor('sms');
        $this->assertIsString($result);
        $this->assertSame('13800138000', $result);
    }

    /** @test */
    public function notifiable_trait_preserves_custom_overrides(): void
    {
        // 测试子类可以覆盖 routeNotificationFor
        $user = new class implements Notifiable {
            use NotifiableTrait;

            public function routeNotificationFor(string $channel): string|array
            {
                // 自定义路由逻辑：email 走自定义，其他保持默认映射
                if ($channel === 'email') {
                    return 'custom@example.com';
                }
                // 手动实现 trait 的默认逻辑（因为匿名类无父类无法 parent::）
                $map = [
                    'email'    => 'email',
                    'sms'      => 'phone',
                    'database' => 'id',
                    'dingtalk' => 'phone',
                ];
                $attribute = $map[$channel] ?? 'id';
                if (isset($this->{$attribute})) {
                    return (string) $this->{$attribute};
                }
                return '';
            }

            public function __get(string $name): mixed
            {
                return match ($name) {
                    'id'    => 1,
                    'email' => 'original@example.com',
                    'phone' => '13800000000',
                    default => null,
                };
            }

            public function __isset(string $name): bool
            {
                return in_array($name, ['id', 'email', 'phone'], true);
            }
        };

        $this->assertSame('custom@example.com', $user->routeNotificationFor('email'));
        $this->assertSame('13800000000', $user->routeNotificationFor('sms'));
    }
}
