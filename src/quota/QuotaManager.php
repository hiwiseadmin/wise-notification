<?php

declare(strict_types=1);

namespace wise\notification\quota;

use wise\notification\throttle\ThrottleManager;
use wise\support\rate\RateLimiter;

/**
 * QuotaManager — 发送配额查询器
 *
 * 只读视角：查询各通道 / 各用户的当日配额使用情况，供后台展示与 `php think
 * wise:notification:health` 输出使用。
 *
 * 重要说明
 * ========
 * 配额的**消费**（hit）统一由 {@see ThrottleManager::shouldThrottle()} 在发送链路完成，
 * 本类复用其同一套缓存键（ThrottleManager::PREFIX_*），因此：
 * - 不会出现"两份配额计数各算各的"导致统计与实际拦截不一致；
 * - 本类不再提供 increment*() 方法——早期版本的实现自建 `notification:quota:` 前缀，
 *   与发送链路的计数完全脱节（永远显示 0 使用量），属于误导性死代码，已移除。
 */
class QuotaManager
{
    /**
     * 检查通道日配额是否已用完
     *
     * @return array{allowed: bool, used: int, limit: int}
     */
    public function checkChannelQuota(string $channel): array
    {
        $limit = (int) (config('wise-notification.quota.per_channel_daily', [])[$channel] ?? 0);

        if ($limit <= 0) {
            return ['allowed' => true, 'used' => 0, 'limit' => 0];
        }

        $used = $this->channelLimiter($channel, $limit)->attempts();

        return [
            'allowed' => $used < $limit,
            'used'    => $used,
            'limit'   => $limit,
        ];
    }

    /**
     * 检查用户日配额是否已用完
     *
     * @return array{allowed: bool, used: int, limit: int}
     */
    public function checkUserQuota(string $notifiableType, string $notifiableId, string $channel): array
    {
        $limit = (int) config('wise-notification.quota.per_user_daily', 20);

        if ($limit <= 0) {
            return ['allowed' => true, 'used' => 0, 'limit' => 0];
        }

        $used = $this->userLimiter($notifiableType, $notifiableId, $channel, $limit)->attempts();

        return [
            'allowed' => $used < $limit,
            'used'    => $used,
            'limit'   => $limit,
        ];
    }

    /**
     * 获取各通道当日配额使用情况
     *
     * @return array<string, array{used: int, limit: int, usage_pct: float, exhausted: bool}>
     */
    public function getDailyStats(): array
    {
        $channels = ['email', 'sms', 'dingtalk', 'wecom', 'feishu', 'webhook', 'wechat_oa', 'database'];
        $quota = (array) config('wise-notification.quota.per_channel_daily', []);
        $stats = [];

        foreach ($channels as $channel) {
            $limit = (int) ($quota[$channel] ?? 0);
            $count = $limit > 0 ? $this->channelLimiter($channel, $limit)->attempts() : 0;

            $stats[$channel] = [
                'used'      => $count,
                'limit'     => $limit,
                'usage_pct' => $limit > 0 ? round(($count / $limit) * 100, 2) : 0,
                'exhausted' => $limit > 0 && $count >= $limit,
            ];
        }

        return $stats;
    }

    // ==================== 私有方法 ====================

    /**
     * 通道日配额限流器（键与 ThrottleManager 完全一致）
     */
    private function channelLimiter(string $channel, int $limit): RateLimiter
    {
        return $this->makeLimiter(ThrottleManager::PREFIX_CHANNEL_DAILY, $limit)
            ->by("{$channel}:" . date('Ymd'));
    }

    /**
     * 用户日配额限流器（键与 ThrottleManager 完全一致）
     */
    private function userLimiter(string $notifiableType, string $notifiableId, string $channel, int $limit): RateLimiter
    {
        return $this->makeLimiter(ThrottleManager::PREFIX_DAILY, $limit)
            ->by("{$channel}:{$notifiableType}:{$notifiableId}:" . date('Ymd'));
    }

    /**
     * 创建日窗口限流器（窗口结束时间 = 当天 24:00，与 ThrottleManager 一致）
     */
    private function makeLimiter(string $prefix, int $limit): RateLimiter
    {
        $endOfDay = max(1, strtotime('tomorrow') - time());

        $limiter = RateLimiter::for($limit, $endOfDay)->withPrefix($prefix);

        $store = config('wise-notification.throttle.cache_store');
        if (is_string($store) && $store !== '') {
            $limiter->via($store);
        }

        return $limiter;
    }
}
