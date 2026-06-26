<?php

declare(strict_types=1);

namespace wise\notification\throttle;

use think\facade\Cache;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;

/**
 * ThrottleManager — 通知节流管理器
 *
 * 提供以下能力：
 *
 * 1. 频率限制 — per用户+per通道窗口内最多 N 条消息
 * 2. Digest 合并 — 同用户同通道同类型消息在窗口内合并为一条摘要
 * 3. 免打扰检测 — 检查当前时间是否在用户免打扰时段
 * 4. 日配额检测 — 检查用户当日接收量是否超限
 */
class ThrottleManager
{
    /** Redis 前缀 */
    private const PREFIX = 'notification:throttle:';

    /**
     * 检查是否应该节流（返回 true 表示需要节流/阻止发送）
     *
     * @param Notifiable $notifiable 接收者
     * @param string     $channel    通道名
     * @param string     $messageType 消息类型（default: general）
     * @return array{throttled: bool, reason: string}
     */
    public function shouldThrottle(
        Notifiable $notifiable,
        string $channel,
        string $messageType = 'general',
    ): array {
        $config = config('wise-notification.throttle', []);
        $enabled = $config['enabled'] ?? true;

        if (!$enabled) {
            return ['throttled' => false, 'reason' => ''];
        }

        $userId = $notifiable->getNotifiableId();
        $userType = $notifiable->getNotifiableType();

        // 1. 检查免打扰时段
        $quietHours = $notifiable->quietHours();
        if ($quietHours !== null) {
            $now = date('H:i');
            $start = $quietHours['start'] ?? '00:00';
            $end = $quietHours['end'] ?? '00:00';

            $inQuietPeriod = false;
            if ($start <= $end) {
                $inQuietPeriod = ($now >= $start && $now <= $end);
            } else {
                // 跨天时段：如 22:00-08:00
                $inQuietPeriod = ($now >= $start || $now <= $end);
            }

            if ($inQuietPeriod) {
                return ['throttled' => true, 'reason' => "quiet_hours: {$start}-{$end}"];
            }
        }

        // 2. 检查窗口频率限制
        $window = $config['window'] ?? 30;
        $maxPerUser = $config['max_per_user'] ?? 10;

        $windowKey = self::PREFIX . "window:{$channel}:{$userType}:{$userId}:{$messageType}";
        $count = $this->incrementWindow($windowKey, $window);

        if ($count > $maxPerUser) {
            return [
                'throttled' => true,
                'reason'    => "rate_limit: {$count}/{$maxPerUser} in {$window}s",
            ];
        }

        // 3. 检查每日配额
        $userDailyMax = $notifiable->maxDailyNotifications($channel);
        $quotaConfig = config('wise-notification.quota', []);
        $globalDailyMax = $quotaConfig['per_user_daily'] ?? 20;
        $effectiveMax = $userDailyMax ?? $globalDailyMax;

        if ($effectiveMax > 0) {
            $dailyKey = self::PREFIX . "daily:{$channel}:{$userType}:{$userId}:" . date('Ymd');
            $dailyCount = $this->incrementDaily($dailyKey);

            if ($dailyCount > $effectiveMax) {
                return [
                    'throttled' => true,
                    'reason'    => "daily_quota: {$dailyCount}/{$effectiveMax}",
                ];
            }
        }

        // 4. 检查通道每日配额
        $channelDailyMax = $quotaConfig['per_channel_daily'][$channel] ?? 0;
        if ($channelDailyMax > 0) {
            $channelDailyKey = self::PREFIX . "channel_daily:{$channel}:" . date('Ymd');
            $channelDailyCount = $this->incrementDaily($channelDailyKey);
            if ($channelDailyCount > $channelDailyMax) {
                return [
                    'throttled' => true,
                    'reason'    => "channel_daily_quota: {$channelDailyCount}/{$channelDailyMax}",
                ];
            }
        }

        return ['throttled' => false, 'reason' => ''];
    }

    /**
     * Digest 合并：将消息加入消化窗口
     *
     * 同用户同通道同类型，在 digest 窗口内的多条消息自动合并。
     * 返回合并后的 digest ID 或 null（不需合并）。
     *
     * @param Notifiable $notifiable
     * @param string     $channel
     * @param Message    $message
     * @param string     $messageType
     * @return string|null digest ID（已存在则返回，不存在则返回 null）
     */
    public function digest(
        Notifiable $notifiable,
        string $channel,
        Message $message,
        string $messageType = 'general',
    ): ?string {
        $config = config('wise-notification.throttle', []);
        $window = $config['window'] ?? 30;
        $userId = $notifiable->getNotifiableId();
        $userType = $notifiable->getNotifiableType();

        $digestKey = self::PREFIX . "digest:{$channel}:{$userType}:{$userId}:{$messageType}";

        // 检查是否已有进行中的 digest
        $existingDigestId = Cache::get($digestKey);
        if ($existingDigestId !== null) {
            // 追加消息到现有 digest
            $this->appendToDigest($existingDigestId, $message);
            return $existingDigestId;
        }

        // 创建新 digest
        $digestId = 'digest_' . uniqid('', true);
        $digestData = [
            'digest_id'    => $digestId,
            'channel'      => $channel,
            'notifiable_id' => $userId,
            'notifiable_type' => $userType,
            'message_type' => $messageType,
            'messages'     => [[
                'title'   => $message->title,
                'content' => $message->content,
                'time'    => date('Y-m-d H:i:s'),
            ]],
            'create_time'   => date('Y-m-d H:i:s'),
        ];

        Cache::set($digestKey, $digestId, $window);
        Cache::set(self::PREFIX . "digest_data:{$digestId}", json_encode($digestData, JSON_UNESCAPED_UNICODE), $window + 60);

        return null; // 首次创建，不需要发送（由定时任务统一发送）
    }

    /**
     * 获取并清空 digest 数据（由定时任务消费）
     *
     * @param string $digestId
     * @return array|null
     */
    public function consumeDigest(string $digestId): ?array
    {
        $dataKey = self::PREFIX . "digest_data:{$digestId}";
        $data = Cache::get($dataKey);

        if ($data === null) {
            return null;
        }

        Cache::delete($dataKey);

        return json_decode($data, true);
    }

    /**
     * 获取所有待处理的 digest ID
     *
     * @return string[]
     */
    public function getPendingDigests(): array
    {
        // 注意：Redis 不支持前缀扫描时此方法需要 adapter 支持
        // 当前使用简单的回调注册机制
        return [];
    }

    // ==================== 私有方法 ====================

    /**
     * 递增滑动窗口计数
     */
    private function incrementWindow(string $key, int $windowSeconds): int
    {
        $count = (int) Cache::get($key);
        $count++;
        Cache::set($key, $count, $windowSeconds);
        return $count;
    }

    /**
     * 递增每日计数
     */
    private function incrementDaily(string $key): int
    {
        $count = (int) Cache::get($key);
        $count++;
        // 缓存到当天结束
        $endOfDay = strtotime('tomorrow') - time();
        Cache::set($key, $count, $endOfDay);
        return $count;
    }

    /**
     * 追加消息到现有 digest
     */
    private function appendToDigest(string $digestId, Message $message): void
    {
        $dataKey = self::PREFIX . "digest_data:{$digestId}";
        $data = Cache::get($dataKey);

        if ($data === null || $data === false) {
            return;
        }

        $digestData = json_decode($data, true);
        if (!is_array($digestData)) {
            return;
        }

        $digestData['messages'][] = [
            'title'   => $message->title,
            'content' => $message->content,
            'time'    => date('Y-m-d H:i:s'),
        ];

        $window = config('wise-notification.throttle.window', 30);
        Cache::set($dataKey, json_encode($digestData, JSON_UNESCAPED_UNICODE), $window + 60);
    }
}
