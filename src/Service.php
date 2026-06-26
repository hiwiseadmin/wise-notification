<?php

declare(strict_types=1);

namespace wise\notification;

use wise\notification\command\Publish;
use wise\notification\command\Send;
use wise\notification\command\RetryFailed;
use wise\notification\command\HealthCheck;
use wise\notification\trait\ServiceTrait;

/**
 * wise-notification 服务提供者
 *
 * 负责注册配置、绑定门面、注册命令。
 * 通过 composer.json 的 extra.think.services 自动加载。
 */
class Service extends \think\Service
{
    use ServiceTrait;

    /**
     * 服务注册
     *
     * 合并配置、绑定容器实例、注册控制台命令。
     */
    public function register(): void
    {
        // 合并包配置到应用配置
        $this->mergeConfig('wise-notification');

        // 绑定 NotificationManager 到容器（Facade 代理目标）
        $this->app->bind('notification', NotificationManager::class);
        $this->app->bind(NotificationManager::class, NotificationManager::class);

        // 仅在控制台环境下注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                Publish::class,
                Send::class,
                RetryFailed::class,
                HealthCheck::class,
            ]);
        }
    }

    /**
     * 服务启动
     */
    public function boot(): void
    {
    }
}
