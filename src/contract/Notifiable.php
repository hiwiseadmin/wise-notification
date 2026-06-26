<?php

declare(strict_types=1);

namespace wise\notification\contract;

/**
 * Notifiable 接口 — 通知接收者契约
 *
 * 任何可接收通知的实体（如 User 模型）必须实现此接口，
 * 提供各通道的路由信息（邮箱地址、手机号、用户 ID 等）。
 *
 * 新增通知偏好能力：
 * - wantsNotification() — 是否接收特定通道的通知
 * - quietHours() — 免打扰时段
 * - maxDailyNotifications() — 每日最大接收量
 */
interface Notifiable
{
    /**
     * 获取指定通道的路由地址
     *
     * @param string $channel 通道名称
     * @return string|array 路由地址
     */
    public function routeNotificationFor(string $channel): string|array;

    /**
     * 是否接收指定通道的通知
     *
     * @param string      $channel 通道名称
     * @param string|null $type    消息类型（可选）
     * @return bool true=接收，false=不接收
     */
    public function wantsNotification(string $channel, ?string $type = null): bool;

    /**
     * 免打扰时段
     *
     * @return array|null 如 ['start' => '22:00', 'end' => '08:00']，null 表示不启用
     */
    public function quietHours(): ?array;

    /**
     * 指定通道的每日最大接收量
     *
     * @param string $channel 通道名称
     * @return int|null null 表示不限制，0 表示不接收该通道
     */
    public function maxDailyNotifications(string $channel): ?int;

    /**
     * 获取通知接收者唯一标识（用于偏好查询/审计）
     */
    public function getNotifiableId(): string;

    /**
     * 获取通知接收者类型（用于偏好查询/审计）
     */
    public function getNotifiableType(): string;
}
