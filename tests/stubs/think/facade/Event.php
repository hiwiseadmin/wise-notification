<?php

declare(strict_types=1);

namespace think\facade;

/**
 * ThinkPHP Event Facade 桩
 */
class Event
{
    /** @var array<int, array{event: object, callable: callable}> */
    private static array $listeners = [];

    /** @var array<int, object> */
    private static array $triggered = [];

    /**
     * 触发事件
     */
    public static function trigger(object $event): void
    {
        self::$triggered[] = $event;
        $eventClass = get_class($event);
        foreach (self::$listeners as $listener) {
            $listenerEvent = $listener['event'];
            // listener can be registered by class string name or object
            $listenerClass = is_object($listenerEvent) ? get_class($listenerEvent) : $listenerEvent;
            if ($eventClass === $listenerClass || $event instanceof $listenerClass) {
                ($listener['callback'])($event);
            }
        }
    }

    /**
     * 监听事件
     */
    public static function listen(string $eventClass, callable $callback): void
    {
        self::$listeners[] = [
            'event' => $eventClass,
            'callback' => $callback,
        ];
    }

    /**
     * 获取已触发的事件列表
     *
     * @return object[]
     */
    public static function getTriggered(): array
    {
        return self::$triggered;
    }

    /**
     * 按类名获取已触发的事件
     *
     * @return object[]
     */
    public static function getTriggeredByClass(string $class): array
    {
        return array_values(array_filter(
            self::$triggered,
            fn ($e) => $e instanceof $class || get_class($e) === $class
        ));
    }

    /**
     * 重置事件状态（测试间清理）
     */
    public static function reset(): void
    {
        self::$listeners = [];
        self::$triggered = [];
    }
}
