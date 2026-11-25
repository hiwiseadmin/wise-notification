<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * 测试用队列门面，与 TestQueueDispatcher 共用同一份静态记录
 */
class TestQueueFacade
{
    public static function push($job, $data = '', $queue = null)
    {
        return (new TestQueueDispatcher())->push($job, $data, $queue);
    }

    public static function later($delay, $job, $data = '', $queue = null)
    {
        return (new TestQueueDispatcher())->later($delay, $job, $data, $queue);
    }

    /**
     * @return list<array{class: string, delay: int, data: mixed, queue: string}>
     */
    public static function getPushed(): array
    {
        return TestQueueDispatcher::getPushed();
    }

    public static function getPushedCount(string $jobClass): int
    {
        return TestQueueDispatcher::getPushedCount($jobClass);
    }

    public static function reset(): void
    {
        TestQueueDispatcher::reset();
    }
}
