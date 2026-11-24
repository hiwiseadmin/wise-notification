<?php

declare(strict_types=1);

namespace wise\notification\middleware;

use wise\notification\contract\SendResult;

/**
 * NotificationMiddleware — 通知中间件接口
 *
 * 管道模式中的中间件契约，可在发送前后插入自定义逻辑。
 * 通过 config('notification.middleware') 或 extendMiddleware() 注册。
 */
interface NotificationMiddleware
{
    /**
     * 处理发送上下文
     *
     * @param SendContext                   $context 发送上下文
     * @param callable(SendContext): SendResult $next    下一个中间件/驱动调用
     * @return SendResult
     */
    public function handle(SendContext $context, callable $next): SendResult;
}
