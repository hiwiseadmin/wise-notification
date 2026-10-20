<?php

declare(strict_types=1);

namespace think\facade;

/**
 * ThinkPHP Queue Facade 桩
 */
class Queue
{
    /** @var array<int, array{class: string, data: array, queue: string}> */
    private static array $pushed = [];

    /**
     * 推送任务到队列
     */
    public static function push(string $jobClass, array $data = [], string $queue = 'default'): void
    {
        self::$pushed[] = [
            'class' => $jobClass,
            'data'  => $data,
            'queue' => $queue,
        ];
    }

    /**
     * 获取已推送的任务列表
     *
     * @return array<int, array{class: string, data: array, queue: string}>
     */
    public static function getPushed(): array
    {
        return self::$pushed;
    }

    /**
     * 获取指定类的推送数量
     */
    public static function getPushedCount(string $jobClass): int
    {
        return count(array_filter(self::$pushed, fn ($j) => $j['class'] === $jobClass));
    }

    /**
     * 重置队列状态
     */
    public static function reset(): void
    {
        self::$pushed = [];
    }
}
