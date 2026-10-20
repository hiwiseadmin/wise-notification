<?php

declare(strict_types=1);

namespace wise\notification\tests;

use PHPUnit\Framework\TestCase;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\model\NotificationHistory;
use wise\notification\preference\PreferenceManager;
use wise\notification\security\DataMasker;
use wise\notification\template\TemplateCompiler;

/**
 * 安全行为回归测试
 *
 * 覆盖 P1/P2 修复引入的安全默认值：
 * - DataMasker 默认脱敏规则（落库脱敏）
 * - NotificationHistory::sanitize()/encodePayload()（脱敏 + 截断 + 非法 UTF-8 兜底）
 * - TemplateCompiler 安全开关（对象取值、原始输出）
 * - PreferenceManager::isCritical()（关键消息豁免判定）
 */
class SecurityBehaviorTest extends TestCase
{
    // ── DataMasker ─────────────────────────────────────────────

    /** @test */
    public function data_masker_has_default_rules(): void
    {
        $this->assertTrue((new DataMasker())->hasRules());
    }

    /** @test */
    public function data_masker_masks_secret_and_recipient_fields(): void
    {
        $masker = new DataMasker();
        $masked = $masker->mask([
            'data' => [
                'code'   => '483920',
                'token'  => 'abcdef123456',
                'phone'  => '13812345678',
                'email'  => 'zhangsan@example.com',
                'order'  => 'NO-2026-001',
                'nested' => ['mobile' => '13900001111'],
            ],
        ]);

        $this->assertSame('***', $masked['data']['code']);
        $this->assertSame('***', $masked['data']['token']);
        $this->assertSame('138****5678', $masked['data']['phone']);
        $this->assertSame('z***@example.com', $masked['data']['email']);
        $this->assertSame('139****1111', $masked['data']['nested']['mobile']);
        $this->assertSame('NO-2026-001', $masked['data']['order'], '非敏感字段不应被改动');
    }

    /** @test */
    public function data_masker_is_idempotent(): void
    {
        $masker = new DataMasker();
        $once   = $masker->mask(['phone' => '13812345678', 'code' => '123456']);
        $twice  = $masker->mask($once);

        $this->assertSame($once, $twice);
    }

    /** @test */
    public function data_masker_ignores_invalid_rule(): void
    {
        // 非法正则（preg_replace 返回 null）不应破坏原值
        $masker = new DataMasker(['phone' => ['pattern' => '/(/', 'replacement' => '***']]);

        $this->assertSame('13812345678', $masker->mask(['phone' => '13812345678'])['phone']);
    }

    /** @test */
    public function mask_string_does_not_blank_out_arbitrary_text(): void
    {
        // 整字段替换规则（code/password/token）不得参与自由文本自动识别，
        // 否则任意非空字符串都会被替换成 ***，历史记录失去排障价值
        $masker = new DataMasker();

        $this->assertSame('{"Code":"OK","BizId":"1234567890"}', $masker->maskString('{"Code":"OK","BizId":"1234567890"}'));
    }

    /** @test */
    public function mask_string_masks_pii_in_free_text(): void
    {
        $masker = new DataMasker();

        $this->assertStringNotContainsString('13812345678', $masker->maskString('send to 13812345678 failed'));
        $this->assertStringNotContainsString('zhangsan@example.com', $masker->maskString('mail to zhangsan@example.com'));
    }

    // ── NotificationHistory::sanitize / encodePayload ──────────

    /** @test */
    public function history_sanitize_masks_payload_response_and_error(): void
    {
        $out = NotificationHistory::sanitize([
            'message_payload' => json_encode(['data' => ['code' => '483920', 'phone' => '13812345678']], JSON_UNESCAPED_UNICODE),
            'response'        => '{"Code":"OK","phone":"13812345678"}',
            'error'           => 'send to 13812345678 failed',
            'status'          => 'sent',
        ]);

        $payload = json_decode((string) $out['message_payload'], true);

        $this->assertSame('***', $payload['data']['code']);
        $this->assertSame('138****5678', $payload['data']['phone']);
        $this->assertStringNotContainsString('13812345678', (string) $out['response']);
        $this->assertStringNotContainsString('13812345678', (string) $out['error']);
        $this->assertSame('sent', $out['status']);
    }

    /** @test */
    public function history_sanitize_truncates_oversized_text(): void
    {
        $out = NotificationHistory::sanitize([
            'response' => str_repeat('a', 200000),
        ]);

        $this->assertLessThan(200000, strlen((string) $out['response']));
        $this->assertStringEndsWith('...[truncated]', (string) $out['response']);
    }

    /** @test */
    public function encode_payload_substitutes_invalid_utf8(): void
    {
        $encoded = NotificationHistory::encodePayload(['bad' => "ok\xB1\x31\xB3\xB4broken"]);

        $this->assertNotSame('{}', $encoded, '非法 UTF-8 不应导致编码失败');
        $this->assertNotFalse(json_decode($encoded, true));
    }

    // ── TemplateCompiler 安全开关 ───────────────────────────────

    /** @test */
    public function template_compiler_denies_object_access_by_default(): void
    {
        $object = new class { public string $name = 'secret-name'; };

        $this->assertSame('x=', (new TemplateCompiler())->render('x={u.name}', ['u' => $object]));
    }

    /** @test */
    public function template_compiler_escapes_raw_output_by_default(): void
    {
        $compiler = new TemplateCompiler();

        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $compiler->render('{!v!}', ['v' => '<b>hi</b>']));
    }

    /** @test */
    public function template_compiler_honours_security_switches_when_enabled(): void
    {
        $object = new class { public string $name = 'secret-name'; };

        $compiler = new TemplateCompiler(
            allowObjectAccess: true,
            allowRawOutput: true,
        );

        $this->assertSame('x=secret-name', $compiler->render('x={u.name}', ['u' => $object]));
        $this->assertSame('<b>hi</b>', $compiler->render('{!v!}', ['v' => '<b>hi</b>']));
    }

    // ── PreferenceManager::isCritical ──────────────────────────

    /** @test */
    public function critical_message_detection(): void
    {
        $pm = new PreferenceManager();

        $this->assertTrue($pm->isCritical(new Message('t', 'c', ['type' => 'verify_code']), 'sms'));
        $this->assertTrue($pm->isCritical(new Message('t', 'c', ['message_type' => 'security']), 'email'));
        $this->assertTrue(
            $pm->isCritical(new Message('t', 'c', ['_priority' => Message::PRIORITY_URGENT]), 'email')
        );
        $this->assertTrue(
            $pm->isCritical(new Message('t', 'c', ['type' => 'content_review']), 'database'),
            'database 通道默认豁免'
        );
        $this->assertFalse($pm->isCritical(new Message('t', 'c', ['type' => 'marketing']), 'email'));
        $this->assertFalse($pm->isCritical(new Message('t', 'c', []), 'email'));
    }

    /** @test */
    public function critical_detection_does_not_generalize(): void
    {
        // 兜底：豁免列表为空时，不应有任何消息被判定为关键
        $pm = new PreferenceManager();
        $this->assertFalse($pm->isCritical(new Message('t', 'c', ['type' => 'marketing']), 'test_never_exempt'));
    }

    // ── 审计日志模型 ────────────────────────────────────────────

    /** @test */
    public function audit_log_records_action_and_defaults_create_time(): void
    {
        $record = \wise\notification\model\NotificationAuditLog::log([
            'action'          => 'throttle',
            'channel'         => 'sms',
            'notifiable_type' => 'user',
            'notifiable_id'   => '1',
            'status'          => 'failed',
            'error'           => 'daily_quota: exceeded 20',
        ]);

        $this->assertSame('throttle', $record->getAttr('action'));
        $this->assertSame('sms', $record->getAttr('channel'));
        $this->assertNotEmpty($record->getAttr('create_time'), 'create_time 应自动补全');
    }

    /** @test */
    public function notifiable_trait_satisfies_interface(): void
    {
        $notifiable = new class('13800138000') implements Notifiable {
            use \wise\notification\traits\NotifiableTrait;

            public function __construct(private readonly string $target) {}

            public function routeNotificationFor(string $channel): string|array
            {
                return $this->target;
            }

            public function getNotifiableId(): string
            {
                return $this->target;
            }

            public function getNotifiableType(): string
            {
                return 'test';
            }
        };

        $this->assertSame('13800138000', $notifiable->routeNotificationFor('sms'));
        $this->assertTrue($notifiable->wantsNotification('sms'));
        $this->assertNull($notifiable->quietHours());
        $this->assertNull($notifiable->maxDailyNotifications('sms'));
    }
}
