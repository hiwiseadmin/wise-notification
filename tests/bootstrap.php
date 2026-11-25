<?php

declare(strict_types=1);

/**
 * wise-notification PHPUnit Bootstrap
 *
 * 关键背景：`tests/stubs/**` 里的 think 桩类用 `if (!class_exists(...))` 守卫，
 * 但 PHPUnit 启动时已通过 `src/vendor/autoload.php` 载入真实 topthink/framework /
 * think-orm，桩类永远不会生效。于是：
 *   - `think\facade\Event` 走真实 Facade → 容器无 `event` → 「class not exists: event」；
 *   - 模型 `save()` 走真实 ORM → `Model::$db` 为 null → 「connect() on null」。
 *
 * 因此这里不再假装「没有框架」，而是搭一个最小可用的框架容器：
 *   配置（think\Config） + 事件（think\Event） + 缓存/日志/队列替身 +
 *   SQLite 内存库（模型用例需要真实写入）。
 */

$autoloadCandidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
    dirname(__DIR__, 5) . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'wise\\notification\\tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

if (!class_exists(WiseNotificationTestCache::class)) {
    class WiseNotificationTestCache
    {
        /** @var array<string, mixed> */
        private array $data = [];

        public function store(?string $name = null): self
        {
            return $this;
        }

        public function tag($tag): self
        {
            return $this;
        }

        public function handler(): self
        {
            return $this;
        }

        public function get(string $key, $default = null)
        {
            return $this->data[$key] ?? $default;
        }

        public function set(string $key, $value, $ttl = null): bool
        {
            $this->data[$key] = $value;

            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->data);
        }

        public function delete(string $key): bool
        {
            unset($this->data[$key]);

            return true;
        }

        public function inc(string $key, int $step = 1): int
        {
            $this->data[$key] = (int) ($this->data[$key] ?? 0) + $step;

            return $this->data[$key];
        }

        public function dec(string $key, int $step = 1): int
        {
            return $this->inc($key, -$step);
        }

        public function clear(): bool
        {
            $this->data = [];

            return true;
        }

        public function __call($method, $args)
        {
            return null;
        }
    }
}

if (!class_exists(WiseNotificationTestNullLogger::class)) {
    class WiseNotificationTestNullLogger
    {
        public function channel(string $name): self
        {
            return $this;
        }

        public function __call($method, $args)
        {
            return null;
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

/**
 * 用例级复位：清空缓存（限流计数）、事件/队列记录、以及库表数据
 *
 * 缓存是进程内单例，限流中间件（rate_limit）与节流器都会累计计数，
 * 不复位会出现「单跑通过、整类跑失败」的顺序依赖。
 */
function wise_notification_test_reset(): void
{
    $container = \think\Container::getInstance();

    $cache = $container->make('cache');
    if (is_object($cache) && method_exists($cache, 'clear')) {
        $cache->clear();
    }

    \wise\notification\tests\helpers\TestEventDispatcher::reset();
    \wise\notification\tests\helpers\TestQueueDispatcher::reset();

    \wise\notification\NotificationManager::resetRegistry();

    $db = $container->make('db');
    foreach (['notification_histories', 'notification_templates', 'notification_preferences', 'notification_audit_logs', 'user_notifications'] as $table) {
        try {
            $db->connect()->execute('DELETE FROM ' . $table);
        } catch (\Throwable) {
        }
    }
}

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        $config = \think\Container::getInstance()->make('config');

        if ($key === null || $key === '') {
            return $config->get();
        }

        return $config->get($key, $default);
    }
}

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = [])
    {
        $container = \think\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }
}

if (!function_exists('event')) {
    function event($event, array $payload = []): void
    {
        \think\Container::getInstance()->make('event')->trigger($event, $payload);
    }
}

$packageConfig = is_file(__DIR__ . '/../config/wise-notification.php')
    ? require __DIR__ . '/../config/wise-notification.php'
    : [];

$configArray = [
    'app'               => ['debug' => true],
    'wise-notification' => $packageConfig,
    'database'          => [
        'default'     => 'test',
        'connections' => [
            'test' => [
                'type'     => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
                'debug'    => false,
            ],
        ],
    ],
];

$testApp = new \think\App();
$config  = new \think\Config();
$config->set($configArray);
$testApp->instance('config', $config);
$testApp->instance(\think\Config::class, $config);

$testCache = new WiseNotificationTestCache();
$testLog   = new WiseNotificationTestNullLogger();
$testQueue = new \wise\notification\tests\helpers\TestQueueDispatcher();
$testEvent = new \wise\notification\tests\helpers\TestEventDispatcher();

$testApp->instance('cache', $testCache);
$testApp->instance(\think\Cache::class, $testCache);

$testApp->instance('log', $testLog);
$testApp->instance(\think\Log::class, $testLog);

$testApp->instance('queue', $testQueue);
$testApp->instance(\think\Queue::class, $testQueue);

$testApp->instance('event', $testEvent);
$testApp->instance(\think\Event::class, $testEvent);

$dbEvent = new \think\Event($testApp);

$testDb = new \think\Db();
$testDb->setConfig($config);
$testDb->setEvent($dbEvent);
$testDb->setLog(static function ($level, $message, array $context = []): void {
});
\think\Model::setDb($testDb);

$testApp->instance('db', $testDb);
$testApp->instance(\think\Db::class, $testDb);

$connection = $testDb->connect();

$connection->execute(<<<'SQL'
CREATE TABLE notification_histories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel TEXT,
    notifiable_type TEXT,
    notifiable_id TEXT,
    message_type TEXT DEFAULT 'direct',
    message_payload TEXT,
    status TEXT DEFAULT 'pending',
    response TEXT,
    error TEXT,
    duration_ms INTEGER DEFAULT 0,
    batch_id TEXT,
    create_time TEXT
)
SQL);

$connection->execute(<<<'SQL'
CREATE TABLE notification_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT,
    name TEXT,
    channel TEXT,
    locale TEXT DEFAULT 'zh_CN',
    title TEXT DEFAULT '',
    content TEXT,
    variables TEXT,
    status INTEGER DEFAULT 1,
    create_time TEXT,
    update_time TEXT
)
SQL);

$connection->execute(<<<'SQL'
CREATE TABLE notification_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notifiable_type TEXT,
    notifiable_id TEXT,
    channel TEXT,
    enabled INTEGER DEFAULT 1,
    daily_limit INTEGER,
    quiet_start TEXT,
    quiet_end TEXT,
    subscribed_types TEXT,
    create_time TEXT,
    update_time TEXT
)
SQL);

$connection->execute(<<<'SQL'
CREATE TABLE notification_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT,
    channel TEXT,
    notifiable_type TEXT,
    notifiable_id TEXT,
    message_type TEXT,
    message_payload TEXT,
    status TEXT DEFAULT 'pending',
    error TEXT,
    duration_ms INTEGER,
    context TEXT,
    create_time TEXT
)
SQL);

$connection->execute(<<<'SQL'
CREATE TABLE user_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    title TEXT DEFAULT '',
    content TEXT,
    type TEXT DEFAULT 'system',
    data TEXT,
    is_read INTEGER NOT NULL DEFAULT 0,
    read_at TEXT,
    create_time TEXT
)
SQL);
