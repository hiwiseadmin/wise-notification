<?php

declare(strict_types=1);

namespace think\facade;

/**
 * ThinkPHP Facade 基类桩
 */
abstract class Facade
{
    /** @var array<string, object> 已解析实例 */
    protected static array $resolvedInstance = [];

    /**
     * 获取 Facade 绑定的实际类名
     */
    abstract protected static function getFacadeClass(): string;

    /**
     * 静态调用代理
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $class = static::getFacadeClass();
        $instance = static::resolveFacadeInstance($class);
        return $instance->{$method}(...$args);
    }

    /**
     * 解析 Facade 实例
     */
    protected static function resolveFacadeInstance(string $class): object
    {
        if (!isset(static::$resolvedInstance[$class])) {
            static::$resolvedInstance[$class] = new $class();
        }
        return static::$resolvedInstance[$class];
    }
}
