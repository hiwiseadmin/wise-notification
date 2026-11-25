<?php
declare(strict_types=1);

namespace wise\notification\exception;

use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 服务不可用异常（熔断触发）
 *
 * 当 wise-notification 某渠道的断路器处于 OPEN 状态时抛出，
 * 表示上游通知服务已被熔断保护拦截。由 SendNotificationJob 捕获后
 * 触发队列退避重试，不立即丢入死信。
 *
 * 注：在队列 Job 上下文中不需要 HTTP 语义处理；queue retry 会自动退避。
 *
 * 错误码：GeneralErrorCode::SERVICE_UNAVAILABLE (151)
 * HTTP：503
 */
class ServiceUnavailableException extends WiseException implements CircuitBreakerExceptionInterface
{
    protected int $retryAfter;

    public function __construct(
        string $message = 'Notification channel is temporarily unavailable (circuit breaker open)',
        int $retryAfter = 60,
        string $channel = '',
        ?\Throwable $previous = null
    ) {
        $this->retryAfter = $retryAfter;
        $msg = $channel ? "{$message} [{$channel}]" : $message;
        parent::__construct(
            GeneralErrorCode::SERVICE_UNAVAILABLE->value,
            $msg,
            503,
            $previous,
            ['retry_after' => $retryAfter, 'channel' => $channel],
        );
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
