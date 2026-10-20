<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;

/**
 * NotificationPreference — 用户通知偏好模型
 *
 * 对应 notification_preferences 表。
 * 每行记录 = 一个用户在一个通道上的偏好配置。
 */
class NotificationPreference extends Model
{
    protected $name = 'notification_preferences';

    /** 是否启用 */
    public function scopeEnabled($query): void
    {
        $query->where('enabled', '=', 1);
    }

    /** 按通道筛选 */
    public function scopeByChannel($query, string $channel): void
    {
        $query->where('channel', '=', $channel);
    }

    /** 按接收者筛选 */
    public function scopeByNotifiable($query, string $type, string $id): void
    {
        $query->where('notifiable_type', '=', $type)
            ->where('notifiable_id', '=', $id);
    }

    /**
     * 获取用户在指定通道上的偏好
     */
    public static function getForChannel(string $notifiableType, string $notifiableId, string $channel): ?self
    {
        return static::byNotifiable($notifiableType, $notifiableId)
            ->byChannel($channel)
            ->find();
    }

    /**
     * 获取用户在指定通道上的偏好（如不存在则创建默认记录）
     */
    public static function getOrCreate(string $notifiableType, string $notifiableId, string $channel): self
    {
        $pref = static::getForChannel($notifiableType, $notifiableId, $channel);
        if ($pref !== null) {
            return $pref;
        }

        try {
            return static::create([
                'notifiable_type' => $notifiableType,
                'notifiable_id'   => $notifiableId,
                'channel'         => $channel,
                'enabled'         => 1,
                'create_time'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // 唯一索引冲突：并发创建时重新读取已存在的记录
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return static::getForChannel($notifiableType, $notifiableId, $channel)
                    ?? throw new \RuntimeException('偏好记录创建失败');
            }
            throw $e;
        }
    }

    /**
     * 获取用户所有通道偏好
     *
     * @return static[]
     */
    public static function getAllForNotifiable(string $notifiableType, string $notifiableId): array
    {
        return static::byNotifiable($notifiableType, $notifiableId)->select()->toArray();
    }

    /**
     * 设置通道开关
     */
    public function setEnabled(bool $enabled): self
    {
        $this->save(['enabled' => $enabled ? 1 : 0, 'update_time' => date('Y-m-d H:i:s')]);
        return $this;
    }

    /**
     * 设置每日上限
     */
    public function setDailyLimit(?int $limit): self
    {
        $this->save(['daily_limit' => $limit, 'update_time' => date('Y-m-d H:i:s')]);
        return $this;
    }

    /**
     * 设置免打扰时段
     */
    public function setQuietHours(?string $start, ?string $end): self
    {
        $this->save([
            'quiet_start' => $start,
            'quiet_end'   => $end,
            'update_time'  => date('Y-m-d H:i:s'),
        ]);
        return $this;
    }

    /**
     * 设置订阅的消息类型
     *
     * @param string[]|null $types null 表示订阅所有类型
     */
    public function setSubscribedTypes(?array $types): self
    {
        $this->save([
            'subscribed_types' => $types === null ? null : json_encode($types, JSON_UNESCAPED_UNICODE),
            'update_time'       => date('Y-m-d H:i:s'),
        ]);
        return $this;
    }

    /**
     * 获取订阅的消息类型列表
     *
     * @return string[]|null
     */
    public function getSubscribedTypes(): ?array
    {
        $raw = $this->getAttr('subscribed_types');
        if (empty($raw)) {
            return null;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return $raw;
    }
}
