<?php
declare(strict_types=1);

namespace wise\notification\connector;

use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\support\connector\ConnectorResult;
use wise\support\connector\ConnectorType;

/**
 * 通知连接器默认实现 Trait
 *
 * 为通知通道（实现 ChannelDriver）补齐 {@see \wise\support\connector\ConnectorInterface}
 * 的通用方法，使其可直接注册到 `ExtPoints::EXTERNAL_CONNECTOR`，由 ConnectorManager
 * 统一发现 / 健康检查 / 配置。
 *
 * 通道的业务方法 send(Notifiable, Message) 由通道自身实现；本 trait 负责把
 * 连接器的 execute() 动态入口映射到 send()（需在 context 提供对象）。
 *
 * 用法：
 * <pre>
 * class EmailChannel implements NotificationConnectorInterface
 * {
 *     use NotificationConnectorTrait;
 *     public function getName(): string { return 'email'; }
 *     public function send(Notifiable $n, Message $m): SendResult { ... }
 * }
 * </pre>
 *
 * @package wise\notification\connector
 */
trait NotificationConnectorTrait
{
    /**
     * 连接器唯一标识：取通道名（如 email / sms / dingtalk）
     */
    public function getId(): string
    {
        return $this->getName();
    }

    public function getType(): ConnectorType
    {
        return ConnectorType::MESSAGE;
    }

    public function getDescription(): string
    {
        return sprintf('通知通道（%s）', $this->getName());
    }

    /**
     * 能力自描述（供管理后台 / AI 发现）
     *
     * @return array<string, array>
     */
    public function getActions(): array
    {
        return [
            'send' => ['description' => '发送通知', 'params' => ['notifiable', 'message']],
        ];
    }

    /**
     * 动态分发：把 action 路由到强类型 send()
     *
     * 通道的业务入口是 send(Notifiable, Message)（对象参数），故动态调用需在
     * context 中提供 notifiable 与 message 对象。
     */
    public function execute(string $action, array $input, array $context = []): ConnectorResult
    {
        if ($action !== 'send') {
            return ConnectorResult::fail(
                sprintf('通知通道 [%s] 不支持操作 [%s]', $this->getName(), $action),
                'UNSUPPORTED_ACTION'
            );
        }

        $notifiable = $context['notifiable'] ?? null;
        $message    = $context['message'] ?? null;

        if (!$notifiable instanceof Notifiable || !$message instanceof Message) {
            return ConnectorResult::fail(
                '通知通道的动态执行需在 context 提供 notifiable 与 message 对象',
                'INVALID_CONTEXT'
            );
        }

        try {
            return ConnectorResult::ok($this->send($notifiable, $message)->toArray());
        } catch (\Throwable $e) {
            return ConnectorResult::fail($e->getMessage(), 'NOTIFY_ERROR');
        }
    }

    /**
     * 健康检查：默认视为可用（通道配置缺失时由调用方经 send 结果判定）
     */
    public function healthCheck(): bool
    {
        return true;
    }

    /**
     * 配置 Schema（默认空，通道配置由 wise-notification 配置驱动）
     *
     * @return array<int, array>
     */
    public function getConfigSchema(): array
    {
        return [];
    }

    public function setConfig(array $config): void
    {
        $this->connectorConfig = $config;
    }

    public function getConfig(): array
    {
        return $this->connectorConfig ?? [];
    }

    /**
     * 连接器配置暂存（避免与通道自身属性冲突）
     *
     * @var array<string, mixed>
     */
    protected array $connectorConfig = [];
}
