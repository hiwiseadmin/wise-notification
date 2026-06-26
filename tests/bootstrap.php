<?php

declare(strict_types=1);

/**
 * wise-notification PHPUnit Bootstrap
 *
 * 设置自动加载、模拟 ThinkPHP 全局函数，
 * 使测试可以在不依赖完整 ThinkPHP 应用的情况下运行。
 * 使用 PHPUnit PHAR 运行：php phpunit.phar -c phpunit.xml.dist
 */

// 1. 加载 Composer autoload（如果存在）
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // 回退：手动注册 PSR-4 自动加载
    spl_autoload_register(function (string $class): void {
        $prefix = 'wise\\notification\\';
        $len = strlen($prefix);

        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $baseDir = __DIR__ . '/../src/';
        $testBaseDir = __DIR__ . '/';

        // 检查是否测试类
        if (strncmp('tests\\', $relativeClass, 6) === 0) {
            $file = $testBaseDir . str_replace('\\', '/', substr($relativeClass, 6)) . '.php';
        } else {
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        }

        if (file_exists($file)) {
            require $file;
        }
    });
}

// 2. 加载 ThinkPHP 基础类桩（当完整框架不可用时）
if (!class_exists('think\\Model')) {
    require_once __DIR__ . '/stubs/think/Model.php';
}

if (!class_exists('think\\facade\\Event')) {
    require_once __DIR__ . '/stubs/think/facade/Event.php';
}

if (!class_exists('think\\facade\\Queue')) {
    require_once __DIR__ . '/stubs/think/facade/Queue.php';
}

if (!class_exists('think\\facade\\Facade')) {
    require_once __DIR__ . '/stubs/think/facade/Facade.php';
}

if (!class_exists('think\\Service')) {
    require_once __DIR__ . '/stubs/think/Service.php';
}

if (!class_exists('think\\queue\\Job')) {
    require_once __DIR__ . '/stubs/think/queue/Job.php';
}

if (!class_exists('think\\console\\Command')) {
    require_once __DIR__ . '/stubs/think/console/Command.php';
}

// 3. 模拟 ThinkPHP 全局辅助函数
if (!function_exists('config')) {
    function config(string $key = '', mixed $default = null): mixed
    {
        static $config = [];
        if (empty($config)) {
            $configFile = __DIR__ . '/../config/notification.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
            }
        }
        if ($key === '') {
            return $config;
        }
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }
}

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = [])
    {
        return \wise\notification\tests\helpers\MockApp::getInstance($abstract, $parameters);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

if (!function_exists('event')) {
    function event($event, array $payload = []): void
    {
        // no-op for testing
    }
}
