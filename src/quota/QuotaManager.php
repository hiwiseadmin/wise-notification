<?php

declare(strict_types=1);

namespace wise\notification\quota;

use think\facade\Cache;

/**
 * QuotaManager — 发送配额管理器
 *
 * 控制各通道和用户的每日发送量，防止滥用。
 */
class QuotaManager
{
    private const PREFIX = 'notification:quota:';

    /**
     * 检查通道日配额是否已用完
     *
     * @return array{allowed: bool, used: int, limit: int}
     */
    public function checkChannelQuota(string $channel): array
    {
        $quota = config('wise-notification.quota.per_channel_daily', []);
        $limit = $quota[$channel] ?? 0;

        if ($limit <= 0) {
            return ['allowed' => true, 'used' => 0, 'limit' => 0];
        }

        $used = $this->getDailyCount("channel:{$channel}");
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
        $limit = config('wise-notification.quota.per_user_daily', 20);

        if ($limit <= 0) {
            return ['allowed' => true, 'used' => 0, 'limit' => 0];
        }

        $used = $this->getDailyCount("user:{$notifiableType}:{$notifiableId}:{$channel}");
        return [
            'allowed' => $used < $limit,
            'used'    => $used,
            'limit'   => $limit,
        ];
    }

    /**
     * 递增通道日计数
     */
    public function incrementChannelQuota(string $channel): int
    {
        return $this->incrementDaily("channel:{$channel}");
    }

    /**
     * 递增用户日计数
     */
    public function incrementUserQuota(string $notifiableType, string $notifiableId, string $channel): int
    {
        return $this->incrementDaily("user:{$notifiableType}:{$notifiableId}:{$channel}");
    }

    /**
     * 获取日统计
     *
     * @return array<string, int>
     */
    public function getDailyStats(): array
    {
        $channels = ['email', 'sms', 'dingtalk', 'wecom', 'feishu', 'webhook', 'wechat_oa', 'database'];
        $stats = [];

        foreach ($channels as $channel) {
            $count = $this->getDailyCount("channel:{$channel}");
            $limit = config("wise-notification.quota.per_channel_daily.{$channel}", 0);
            $stats[$channel] = [
                'used'        => $count,
                'limit'       => $limit,
                'usage_pct'   => $limit > 0 ? round(($count / $limit) * 100, 2) : 0,
                'exhausted'   => $limit > 0 && $count >= $limit,
            ];
        }

        return $stats;
    }

    // ==================== 私有方法 ====================

    private function getDailyCount(string $key): int
    {
        return (int) Cache::get(self::PREFIX . $key . ':' . date('Ymd'));
    }

    private function incrementDaily(string $key): int
    {
        $fullKey = self::PREFIX . $key . ':' . date('Ymd');
        $count = (int) Cache::get($fullKey);
        $count++;
        $endOfDay = strtotime('tomorrow') - time();
        Cache::set($fullKey, $count, $endOfDay);
        return $count;
    }
}
