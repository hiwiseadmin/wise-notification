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
    /** @var array<string, array> 内存缓存 */
    private static array $cache = [];

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
        // 1. 检查偏好开关
        if (!$this->wantsNotification($notifiable, $channel, $messageType)) {
            return ['blocked' => true, 'reason' => 'user_disabled'];
        }

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

            // 紧急消息不受免打扰限制
            if ($inQuiet && !$message->isUrgent()) {
                return ['blocked' => true, 'reason' => "quiet_hours: {$start}-{$end}"];
            }
        }

        // 3. 检查节流
        $throttleManager = new ThrottleManager();
        $throttleResult = $throttleManager->shouldThrottle($notifiable, $channel, $messageType);
        if ($throttleResult['throttled']) {
            return ['blocked' => true, 'reason' => $throttleResult['reason']];
        }

        return ['blocked' => false, 'reason' => ''];
    }

    // ==================== 私有方法 ====================

    private function getPreference(Notifiable $notifiable, string $channel): ?array
    {
        $key = $this->cacheKey($notifiable, $channel);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $pref = NotificationPreference::getForChannel(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $channel,
        );

        if ($pref === null) {
            self::$cache[$key] = null;
            return null;
        }

        $data = $pref->toArray();
        $data['subscribed_types'] = $pref->getSubscribedTypes();
        self::$cache[$key] = $data;

        return $data;
    }

    private function cacheKey(Notifiable $notifiable, string $channel): string
    {
        return $notifiable->getNotifiableType() . ':' . $notifiable->getNotifiableId() . ':' . $channel;
    }
}
