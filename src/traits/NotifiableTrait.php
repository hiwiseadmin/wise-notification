<?php

declare(strict_types=1);

namespace wise\notification\traits;

use wise\notification\contract\Message;
use wise\notification\contract\SendResult;
use wise\notification\contract\BatchSendResult;
use wise\notification\NotificationManager;
use wise\notification\model\UserNotification;

/**
 * NotifiableTrait — Notifiable 接口默认实现
 *
 * 为模型提供开箱即用的通知路由能力和偏好管理。
 * 使用此 trait 的模型需实现 Notifiable 接口，且必须继承 think\Model
 * （该 trait 依赖 Model 的关系方法 hasMany，见 notifications()）。
 *
 * @mixin \think\Model
 * @method \think\model\relation\HasMany hasMany($model, $foreignKey = null, $localKey = null)
 *
 * 通道路由映射：
 * - email     → $this->email 属性
 * - sms       → $this->phone 属性
 * - database  → $this->id 属性
 * - dingtalk  → $this->phone 属性
 * - wecom     → $this->wecom_userid 属性
 * - feishu    → $this->feishu_openid 属性
 * - webhook   → $this->id 属性
 * - wechat_oa → $this->wechat_openid 属性
 */
trait NotifiableTrait
{
    /**
     * 获取指定通道的路由地址
     */
    public function routeNotificationFor(string $channel): string|array
    {
        $map = [
            'email'     => 'email',
            'sms'       => 'phone',
            'database'  => 'id',
            'dingtalk'  => 'phone',
            'wecom'     => 'wecom_userid',
            'feishu'    => 'feishu_openid',
            'webhook'   => 'id',
            'wechat_oa' => 'wechat_openid',
        ];

        $attribute = $map[$channel] ?? 'id';

        if (isset($this->{$attribute})) {
            return (string) $this->{$attribute};
        }

        return '';
    }

    /**
     * 是否接收指定通道的通知（默认全部接收）
     */
    public function wantsNotification(string $channel, ?string $type = null): bool
    {
        return true;
    }

    /**
     * 免打扰时段（默认不启用）
     */
    public function quietHours(): ?array
    {
        return null;
    }

    /**
     * 指定通道的每日最大接收量（默认不限制）
     */
    public function maxDailyNotifications(string $channel): ?int
    {
        return null;
    }

    /**
     * 获取通知接收者唯一标识
     */
    public function getNotifiableId(): string
    {
        if (method_exists($this, 'getKey')) {
            return (string) $this->getKey();
        }
        return '';
    }

    /**
     * 获取通知接收者类型
     */
    public function getNotifiableType(): string
    {
        return static::class;
    }

    /**
     * 关联站内信
     */
    public function notifications(): \think\model\relation\HasMany
    {
        return $this->hasMany(UserNotification::class, 'user_id');
    }

    /**
     * 便捷发送通知
     */
    public function notify(Message $message, string|array|null $channel = null): SendResult|BatchSendResult|null
    {
        /** @var NotificationManager $manager */
        $manager = app()->make(NotificationManager::class);

        if ($channel !== null) {
            $manager->channel($channel);
        }

        return $manager->send($this, $message);
    }
}
