<?php

declare(strict_types=1);

namespace wise\notification\preference;

use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\model\NotificationPreference;
use wise\notification\throttle\ThrottleManager;

/**
 * PreferenceManager — 用户通知偏好管理器
 *
 * 聚合数据库偏好 + Notifiable 接口偏好，提供统一的偏好查询入口。
 * 数据库偏好优先于接口默认值。
 */
class PreferenceManager
{
    /**
     * 内存缓存：value = 偏好数组（或 null），expires_at = 过期时间戳
     *
     * 必须带 TTL：队列 Worker / Swoole 等常驻进程中静态数组不会随请求结束失效，
     * 永久缓存会让用户在后台关闭通道后仍被判定为「允许发送」。
     *
     * @var array<string, array{value: array|null, expires_at: int}>
     */
    private static array $cache = [];

    /** 偏好缓存有效期（秒） */
    private const CACHE_TTL = 60;

    /** 缓存条目上限（type:id:channel 组合数无界，需兜底防内存单调增长） */
    private const CACHE_MAX_ENTRIES = 1000;

    /** @var ThrottleManager */
    private readonly ThrottleManager $throttleManager;

    public function __construct(?ThrottleManager $throttleManager = null)
    {
        $this->throttleManager = $throttleManager ?? new ThrottleManager();
    }

    /**
     * 检查用户是否接收指定通道的通知
     */
    public function wantsNotification(Notifiable $notifiable, string $channel, ?string $type = null): bool
    {
        // 1. 检查接口默认值
        if (!$notifiable->wantsNotification($channel, $type)) {
            return false;
        }

        // 2. 检查数据库偏好（如果记录存在）
        $pref = $this->getPreference($notifiable, $channel);
        if ($pref !== null) {
            // 通道已关闭
            if (((int) ($pref['enabled'] ?? 1)) === 0) {
                return false;
            }

            // 类型订阅检查
            $subscribedTypes = $pref['subscribed_types'] ?? null;
            if ($subscribedTypes !== null && $type !== null) {
                if (!in_array($type, $subscribedTypes, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 获取用户免打扰时段
     *
     * 优先级：数据库偏好 > Notifiable::quietHours()
     */
    public function getQuietHours(Notifiable $notifiable, string $channel): ?array
    {
        $pref = $this->getPreference($notifiable, $channel);
        if ($pref !== null) {
            $start = $pref['quiet_start'] ?? null;
            $end = $pref['quiet_end'] ?? null;
            if ($start !== null && $end !== null) {
                return ['start' => $start, 'end' => $end];
            }
        }

        return $notifiable->quietHours();
    }

    /**
     * 获取用户每日通知上限
     *
     * 优先级：数据库偏好 > Notifiable::maxDailyNotifications()
     */
    public function getDailyLimit(Notifiable $notifiable, string $channel): ?int
    {
        $pref = $this->getPreference($notifiable, $channel);
        if ($pref !== null) {
            $limit = $pref['daily_limit'] ?? null;
            if ($limit !== null) {
                return (int) $limit;
            }
        }

        return $notifiable->maxDailyNotifications($channel);
    }

    /**
     * 保存/更新用户通知偏好
     */
    public function savePreference(
        Notifiable $notifiable,
        string $channel,
        array $data,
    ): NotificationPreference {
        $pref = NotificationPreference::getOrCreate(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $channel,
        );

        if (array_key_exists('enabled', $data)) {
            $pref = $pref->setEnabled((bool) $data['enabled']);
        }
        if (array_key_exists('daily_limit', $data)) {
            $pref = $pref->setDailyLimit($data['daily_limit']);
        }
        if (array_key_exists('quiet_start', $data) && array_key_exists('quiet_end', $data)) {
            $pref = $pref->setQuietHours($data['quiet_start'], $data['quiet_end']);
        }
        if (array_key_exists('subscribed_types', $data)) {
            $pref = $pref->setSubscribedTypes($data['subscribed_types']);
        }

        // 清除缓存
        unset(self::$cache[$this->cacheKey($notifiable, $channel)]);

        return $pref;
    }

    /**
     * 获取用户所有偏好
     */
    public function getAllPreferences(Notifiable $notifiable): array
    {
        return NotificationPreference::getAllForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
        );
    }

    /**
     * 批量更新偏好
     *
     * @param array<string, array> $preferences { channel => { enabled: 1, daily_limit: 10 } }
     */
    public function saveAllPreferences(Notifiable $notifiable, array $preferences): void
    {
        foreach ($preferences as $channel => $data) {
            $this->savePreference($notifiable, $channel, $data);
        }
    }

    /**
     * 检查是否需要节流（综合偏好 + 频率 + 配额）
     */
    public function shouldBlock(
        Notifiable $notifiable,
        string $channel,
        Message $message,
        string $messageType = 'general',
    ): array {
        // 1. 检查偏好开关（用户显式关闭通道时不因"关键消息"强制绕过）
        if (!$this->wantsNotification($notifiable, $channel, $messageType)) {
            return ['blocked' => true, 'reason' => 'user_disabled'];
        }

        // 关键消息：紧急优先级 / 验证码 / 安全告警 / 指定通道
        // 不参与免打扰与日配额判定（仍受窗口频率限制约束）
        $critical = $this->isCritical($message, $channel, $messageType);

        // 2. 检查免打扰
        $quietHours = $this->getQuietHours($notifiable, $channel);
        if ($quietHours !== null) {
            $now = date('H:i');
            $start = $quietHours['start'];
            $end = $quietHours['end'];

            $inQuiet = false;
            if ($start <= $end) {
                $inQuiet = ($now >= $start && $now <= $end);
            } else {
                $inQuiet = ($now >= $start || $now <= $end);
            }

            // 关键消息不受免打扰限制
            if ($inQuiet && !$critical) {
                return ['blocked' => true, 'reason' => "quiet_hours: {$start}-{$end}"];
            }
        }

        // 3. 检查节流（免打扰已在上方按优先级处理，此处不再重复判定）
        $throttleResult = $this->throttleManager->shouldThrottle(
            $notifiable,
            $channel,
            $messageType,
            false,
            !$critical,
        );
        if ($throttleResult['throttled']) {
            return ['blocked' => true, 'reason' => $throttleResult['reason']];
        }

        return ['blocked' => false, 'reason' => ''];
    }

    /**
     * 判断是否属于"关键消息"
     *
     * 关键消息不参与免打扰与日配额判定（仍受窗口频率限制约束），
     * 避免验证码、安全告警、站内信等事务型通知被配额静默丢弃。
     *
     * 判定顺序（命中任一即为关键消息）：
     *   1. Message 优先级为 urgent；
     *   2. 通道命中 `throttle.critical.channels`；
     *   3. 消息类型命中 `throttle.critical.types`
     *      （依次比对 $messageType、Message.data['message_type']、Message.data['type']）。
     *
     * @param Message $message     消息体
     * @param string  $channel     通道名
     * @param string  $messageType 消息类型
     */
    public function isCritical(Message $message, string $channel, string $messageType = 'general'): bool
    {
        if ($message->isUrgent()) {
            return true;
        }

        $critical = (array) config('wise-notification.throttle.critical', []);

        $channels = (array) ($critical['channels'] ?? []);
        if ($channels !== [] && in_array($channel, $channels, true)) {
            return true;
        }

        $types = (array) ($critical['types'] ?? []);
        if ($types === []) {
            return false;
        }

        $candidates = [
            $messageType,
            (string) $message->get('message_type', ''),
            (string) $message->get('type', ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, $types, true)) {
                return true;
            }
        }

        return false;
    }

    // ==================== 私有方法 ====================

    private function getPreference(Notifiable $notifiable, string $channel): ?array
    {
        $key = $this->cacheKey($notifiable, $channel);
        $now = time();

        // 命中且未过期才复用；过期立即失效，避免常驻进程内读到陈旧偏好
        if (isset(self::$cache[$key]) && self::$cache[$key]['expires_at'] > $now) {
            return self::$cache[$key]['value'];
        }
        unset(self::$cache[$key]);

        if (count(self::$cache) >= self::CACHE_MAX_ENTRIES) {
            array_shift(self::$cache);
        }

        $pref = NotificationPreference::getForChannel(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $channel,
        );

        if ($pref === null) {
            self::$cache[$key] = ['value' => null, 'expires_at' => $now + self::CACHE_TTL];
            return null;
        }

        $data = $pref->toArray();
        $data['subscribed_types'] = $pref->getSubscribedTypes();
        self::$cache[$key] = ['value' => $data, 'expires_at' => $now + self::CACHE_TTL];

        return $data;
    }

    private function cacheKey(Notifiable $notifiable, string $channel): string
    {
        return $notifiable->getNotifiableType() . ':' . $notifiable->getNotifiableId() . ':' . $channel;
    }
}
