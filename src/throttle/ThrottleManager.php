<?php

declare(strict_types=1);

namespace wise\notification\throttle;

use think\facade\Cache;
use wise\notification\contract\Notifiable;
use wise\notification\model\NotificationAuditLog;
use wise\support\rate\RateLimiter;

/**
 * ThrottleManager — 通知节流管理器
 *
 * 提供以下能力：
 *
 * 1. 频率限制 — per用户+per通道窗口内最多 N 条消息
 * 2. 免打扰检测 — 检查当前时间是否在用户免打扰时段
 * 3. 日配额检测 — 检查用户 / 通道当日发送量是否超限
 *
 * 注：早期版本在类注释中声明过 "Digest 合并" 能力，但 getPendingDigests() 恒返回空数组、
 * 且没有任何消费者调用，属于未实现的假能力，已移除。如需摘要合并，请在业务侧聚合后单条发送。
 */
class ThrottleManager
{
    /** 缓存键前缀（公开：QuotaManager 复用同一套计数键，避免出现第二份配额计数） */
    public const PREFIX = 'notification:throttle:';

    /** 窗口频率限制键前缀 */
    public const PREFIX_WINDOW = self::PREFIX . 'window';

    /** 用户日配额键前缀 */
    public const PREFIX_DAILY = self::PREFIX . 'daily';

    /** 通道日配额键前缀 */
    public const PREFIX_CHANNEL_DAILY = self::PREFIX . 'channel_daily';

    /**
     * 同一用户同一原因写入节流审计的最小间隔（秒）
     *
     * 节流拦截可由高频请求反复触发，若无去重会把审计表打成写放大通道，
     * 故同一 (通道|接收者|原因) 在窗口内只落一条审计。
     */
    private const AUDIT_DEDUPE_TTL = 60;

    /**
     * 检查是否应该节流（返回 true 表示需要节流/阻止发送）
     *
     * @param Notifiable $notifiable        接收者
     * @param string     $channel           通道名
     * @param string     $messageType       消息类型（default: general）
     * @param bool       $includeQuietHours 是否在本方法内判定免打扰时段。
     *                                      PreferenceManager 已按优先级（紧急/关键消息放行）
     *                                      处理过免打扰，其调用时传 false 避免重复判定，
     *                                      否则紧急消息仍会被此处拦截。
     * @param bool       $includeQuota      是否检查每日配额（用户 + 通道）。
     *                                      关键消息（验证码/安全告警等）传 false 跳过，
     *                                      但仍保留窗口频率限制。
     * @return array{throttled: bool, reason: string}
     */
    public function shouldThrottle(
        Notifiable $notifiable,
        string $channel,
        string $messageType = 'general',
        bool $includeQuietHours = true,
        bool $includeQuota = true,
    ): array {
        $config = config('wise-notification.throttle', []);
        $enabled = $config['enabled'] ?? true;

        if (!$enabled) {
            return ['throttled' => false, 'reason' => ''];
        }

        $userId = $notifiable->getNotifiableId();
        $userType = $notifiable->getNotifiableType();

        // 1. 检查免打扰时段
        if ($includeQuietHours) {
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
                    return $this->throttled($notifiable, $channel, $messageType, "quiet_hours: {$start}-{$end}");
                }
            }
        }

        // 计数后端（redis / file 缓存）不可用时 fail-open：节流属于保护性措施，
        // 不应因其自身故障阻断通知投递（免打扰判定在上方，不受影响）。
        try {
            // 2. 检查窗口频率限制
            $window = $config['window'] ?? 30;
            $maxPerUser = $config['max_per_user'] ?? 10;

            $wLimiter = $this->limiter($maxPerUser, $window, self::PREFIX_WINDOW)
                ->by("{$channel}:{$userType}:{$userId}:{$messageType}");

            if ($wLimiter->tooManyAttempts()) {
                return $this->throttled(
                    $notifiable,
                    $channel,
                    $messageType,
                    "rate_limit: exceeded {$maxPerUser} in {$window}s",
                );
            }
            $wLimiter->hit();

            // 3/4. 检查每日配额（关键消息跳过：日配额只约束营销 / 批量类通知）
            if ($includeQuota) {
                // 3. 检查用户每日配额
                $userDailyMax = $notifiable->maxDailyNotifications($channel);
                $quotaConfig = config('wise-notification.quota', []);
                $globalDailyMax = $quotaConfig['per_user_daily'] ?? 20;
                $effectiveMax = $userDailyMax ?? $globalDailyMax;

                if ($effectiveMax > 0) {
                    $endOfDay = max(1, strtotime('tomorrow') - time());
                    $dLimiter = $this->limiter($effectiveMax, $endOfDay, self::PREFIX_DAILY)
                        ->by("{$channel}:{$userType}:{$userId}:" . date('Ymd'));

                    if ($dLimiter->tooManyAttempts()) {
                        return $this->throttled(
                            $notifiable,
                            $channel,
                            $messageType,
                            "daily_quota: exceeded {$effectiveMax}",
                        );
                    }
                    $dLimiter->hit();
                }

                // 4. 检查通道每日配额
                $channelDailyMax = $quotaConfig['per_channel_daily'][$channel] ?? 0;
                if ($channelDailyMax > 0) {
                    $endOfDay = max(1, strtotime('tomorrow') - time());
                    $cdLimiter = $this->limiter($channelDailyMax, $endOfDay, self::PREFIX_CHANNEL_DAILY)
                        ->by("{$channel}:" . date('Ymd'));

                    if ($cdLimiter->tooManyAttempts()) {
                        return $this->throttled(
                            $notifiable,
                            $channel,
                            $messageType,
                            "channel_daily_quota: exceeded {$channelDailyMax}",
                        );
                    }
                    $cdLimiter->hit();
                }
            }
        } catch (\Throwable $e) {
            error_log('[wise-notification] 节流检查失败，已放行（fail-open）: ' . $e->getMessage());
            return ['throttled' => false, 'reason' => ''];
        }

        return ['throttled' => false, 'reason' => ''];
    }

    /**
     * 创建限流器并应用配置的缓存后端
     *
     * 默认走支持原子 inc() 的 redis 驱动（多机共享、无 TOCTOU 竞态）；
     * `throttle.cache_store` 留空时沿用 wise-support RateLimiter 的默认（file）。
     */
    private function limiter(int $maxAttempts, int $decaySeconds, string $prefix): RateLimiter
    {
        $limiter = RateLimiter::for($maxAttempts, $decaySeconds)->withPrefix($prefix);

        $store = config('wise-notification.throttle.cache_store');
        if (is_string($store) && $store !== '') {
            $limiter->via($store);
        }

        return $limiter;
    }

    // ==================== 私有方法 ====================

    /**
     * 构造节流结果并写入审计（action=throttle）
     *
     * @return array{throttled: bool, reason: string}
     */
    private function throttled(Notifiable $notifiable, string $channel, string $messageType, string $reason): array
    {
        $this->writeAudit($notifiable, $channel, $messageType, $reason);

        return ['throttled' => true, 'reason' => $reason];
    }

    /**
     * 写入节流审计日志
     *
     * 节流拦截是 notification_histories 无法表达为独立动作的事件，故写入
     * notification_audit_logs；同一 (通道|接收者|原因) 在 AUDIT_DEDUPE_TTL
     * 窗口内只落一条，避免被高频请求打成写放大通道。审计失败不影响节流判定。
     */
    private function writeAudit(Notifiable $notifiable, string $channel, string $messageType, string $reason): void
    {
        try {
            $dedupeKey = self::PREFIX . 'audit:' . md5(
                "{$channel}|{$messageType}|{$reason}|"
                . $notifiable->getNotifiableType() . '|' . $notifiable->getNotifiableId()
            );

            if (Cache::get($dedupeKey) !== null) {
                return;
            }
            Cache::set($dedupeKey, 1, self::AUDIT_DEDUPE_TTL);

            NotificationAuditLog::log([
                'action'          => 'throttle',
                'channel'         => $channel,
                'notifiable_type' => $notifiable->getNotifiableType(),
                'notifiable_id'   => $notifiable->getNotifiableId(),
                'message_type'    => $messageType,
                'status'          => 'failed',
                'error'           => $reason,
                'create_time'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[wise-notification] 节流审计写入失败: ' . $e->getMessage());
        }
    }
}
