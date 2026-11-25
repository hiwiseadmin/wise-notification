<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * 测试用事件门面
 *
 * 与 `TestEventDispatcher`（容器中的 `think\Event`）共用同一份静态记录，
 * 因此测试里的 `EventFacade::trigger()` 与业务代码的 `Event::trigger()`
 * 看到的是同一个事件流。
 */
class TestEventFacade
{
    public static function trigger($event, $params = null, bool $once = false): void
    {
        (new TestEventDispatcher())->trigger($event, $params, $once);
    }

    public static function listen(string $eventClass, callable $callback): void
    {
        (new TestEventDispatcher())->listen($eventClass, $callback);
    }

    /**
     * @return list<object|string>
     */
    public static function getTriggered(): array
    {
        return TestEventDispatcher::getTriggered();
    }

    /**
     * @return list<object|string>
     */
    public static function getTriggeredByClass(string $class): array
    {
        return TestEventDispatcher::getTriggeredByClass($class);
    }

    public static function reset(): void
    {
        TestEventDispatcher::reset();
    }
}
