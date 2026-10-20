<?php
declare(strict_types=1);

namespace wise\notification\connector;

use wise\notification\channel\ChannelDriver;
use wise\support\connector\ConnectorInterface;

/**
 * 通知连接器契约（领域子接口）
 *
 * 组合连接器通用契约 {@see ConnectorInterface} 与通知通道契约 {@see ChannelDriver}，
 * 使通知通道（邮件/短信/钉钉/企微/飞书/公众号/Webhook/站内信）统一纳入连接器体系，
 * 取代原 `notification.channel` 扩展点。
 *
 * 契约归属说明：本接口依赖 ChannelDriver（位于 wise-notification），
 * 故定义在 wise-notification（而非 wise-support）。
 *
 * 类型归属：{@see \wise\support\connector\ConnectorType::MESSAGE}。
 *
 * 注意：通道的业务入口是强类型的 {@see ChannelDriver::send()}（接收 Notifiable / Message
 * 对象）；连接器的 execute() 仅作动态兜底，需在 context 中提供 notifiable 与 message 对象。
 *
 * @package wise\notification\connector
 */
interface NotificationConnectorInterface extends ConnectorInterface, ChannelDriver
{
}
