<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * MockApp — 模拟 ThinkPHP App 容器
 *
 * 为测试环境提供一个最小化的容器实现，
 * 支持 bind / make 操作及 Facade 解析。
 */
class MockApp
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var self|null */
    private static ?self $instance = null;

    /**
     * 获取 MockApp 单例
     */
    public static function getInstance(?string $abstract = null, array $parameters = []): mixed
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        if ($abstract === null) {
            return self::$instance;
        }

        return self::$instance->make($abstract, $parameters);
    }

    /**
     * 绑定抽象到具体实现
     */
    public function bind(string $abstract, mixed $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * 注册已解析的实例
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * 从容器解析实例
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // 优先返回已注册的实例
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // 检查绑定
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            if (is_string($concrete) && class_exists($concrete)) {
                return new $concrete();
            }
            return $concrete;
        }

        // 尝试直接实例化
        if (class_exists($abstract)) {
            return new $abstract();
        }

        return null;
    }

    /**
     * 重置单例（测试间清理）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 检查是否在控制台环境
     */
    public function runningInConsole(): bool
    {
        return true;
    }
}
