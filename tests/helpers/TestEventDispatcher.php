<?php

declare(strict_types=1);

namespace wise\notification\tests\helpers;

/**
 * 事件分发器替身
 *
 * 原方案依赖 `tests/stubs/think/facade/Event.php`，但真实框架类总是先被 composer
 * 自动加载命中，桩类永远不会生效（真实 `think\Event` 没有 reset()/getTriggered()）。
 * 因此改为：把本类实例绑进容器（服务名 `think\Event`），业务代码照常通过
 * `Event` 门面触发事件，测试侧则可断言与复位。
 */
class TestEventDispatcher
{
    /** @var list<object|string> */
    private static array $triggered = [];

    /** @var list<array{event: object|string, callback: callable}> */
    private static array $listeners = [];

    public function trigger($event, $params = null, bool $once = false): void
    {
        self::$triggered[] = $event;

        $eventClass = is_object($event) ? get_class($event) : (string) $event;

        foreach (self::$listeners as $listener) {
            $listenerClass = is_object($listener['event']) ? get_class($listener['event']) : (string) $listener['event'];

            $hit = $eventClass === $listenerClass
                || (is_object($event) && $event instanceof $listenerClass);

            if ($hit) {
                ($listener['callback'])($event, $params);
            }
        }
    }

    public function listen(string $eventClass, callable $callback): void
    {
        self::$listeners[] = ['event' => $eventClass, 'callback' => $callback];
    }

    /**
     * @return list<object|string>
     */
    public static function getTriggered(): array
    {
        return self::$triggered;
    }

    /**
     * @return list<object|string>
     */
    public static function getTriggeredByClass(string $class): array
    {
        return array_values(array_filter(
            self::$triggered,
            static fn ($e) => is_object($e)
                ? ($e instanceof $class || get_class($e) === $class)
                : $e === $class
        ));
    }

    public static function reset(): void
    {
        self::$triggered = [];
        self::$listeners = [];
    }
}
