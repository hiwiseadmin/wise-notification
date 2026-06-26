<?php

declare(strict_types=1);

namespace think;

/**
 * ThinkPHP Service 基类桩
 */
abstract class Service
{
    /** @var object App 容器实例 */
    protected $app;

    /**
     * 服务注册
     */
    abstract public function register(): void;

    /**
     * 服务启动
     */
    public function boot(): void
    {
    }

    /**
     * 注册控制台命令
     */
    protected function commands(array $commands): void
    {
        // no-op for testing
    }
}
