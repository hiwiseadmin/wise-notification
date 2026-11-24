<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * 队列分发器替身（见 TestEventDispatcher 的说明）
 */
class TestQueueDispatcher
{
    /** @var list<array{class: string, delay: int, data: mixed, queue: string}> */
    private static array $pushed = [];

    public function push($job, $data = '', $queue = null)
    {
        self::$pushed[] = ['class' => (string) $job, 'delay' => 0, 'data' => $data, 'queue' => (string) $queue];

        return count(self::$pushed);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        self::$pushed[] = ['class' => (string) $job, 'delay' => (int) $delay, 'data' => $data, 'queue' => (string) $queue];

        return count(self::$pushed);
    }

    /**
     * @return list<array{class: string, delay: int, data: mixed, queue: string}>
     */
    public static function getPushed(): array
    {
        return self::$pushed;
    }

    public static function getPushedCount(string $jobClass): int
    {
        return count(array_filter(self::$pushed, static fn ($job) => $job['class'] === $jobClass));
    }

    public static function reset(): void
    {
        self::$pushed = [];
    }
}
