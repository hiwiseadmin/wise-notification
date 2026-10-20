<?php

declare(strict_types=1);

namespace wise\notification;

use think\facade\Cache;
use wise\notification\contract\BatchSendResult;
use wise\notification\contract\Message;
use wise\notification\contract\Notifiable;
use wise\notification\contract\SendResult;

/**
 * CodeManager — 验证码管理器（短信 / 邮件通用）
 *
 * 职责边界：本类只负责"码"——生成、缓存、校验、发送频控；**投递**委托给
 * {@see NotificationManager}（本类不感知具体渠道实现）。
 *
 * 存在原因（历史缺陷修复）
 * ======================
 * `wise-member` 的认证服务历史上引用 `\wise\notification\Manager` 这个**并不存在**的类
 * 并调用 `verify()`，导致：
 *   - 短信登录、短信找回密码恒抛「短信验证功能尚未就绪」(500002)，被误判为"环境未配置"；
 *   - 注册流程因缺少校验能力，直接跳过短信验证码校验，任意手机号可被注册。
 * 本类是该校验能力的正式实现，调用方统一改用本类。
 *
 * 缓存键（前缀固定，便于排查与清理）
 * ==============================
 *   wise:notify:code:{scene}:{target}     验证码明文（TTL = code.ttl）
 *   wise:notify:code_try:{scene}:{target} 失败次数（TTL = code.ttl）
 *   wise:notify:code_cd:{scene}:{target}  重发冷却     （TTL = code.cooldown）
 *   wise:notify:code_day:{scene}:{target}:{Ymd} 当日发送计数（TTL = 当日剩余秒）
 *
 * 安全要点
 * ======
 *   - 校验使用 `hash_equals()`，避免时序侧信道；
 *   - 校验成功后**立即消费**（删除），杜绝同一验证码重放；
 *   - 失败次数超限即作废验证码，防止暴力枚举；
 *   - 冷却与每日上限用于抵御短信轰炸。
 */
class CodeManager
{
    /** 短信场景标识 */
    public const SCENE_SMS = 'sms';

    /** 邮件场景标识 */
    public const SCENE_EMAIL = 'email';

    /** 默认策略（可被 config('wise-notification.code') 覆盖） */
    protected const DEFAULTS = [
        'length'       => 6,     // 验证码位数（4-8）
        'ttl'          => 300,   // 有效期（秒）
        'cooldown'     => 60,    // 同一目标重发间隔（秒）
        'max_per_day'  => 10,    // 同一目标每日发送上限
        'max_attempts' => 5,     // 同一验证码最大校验失败次数
        'template'     => '【{site}】您的验证码是 {code}，{minutes} 分钟内有效，请勿泄露给他人。',
    ];

    public function __construct(
        protected readonly \think\App $app,
    ) {
    }

    /**
     * 生成并缓存验证码（不负责投递），返回明文验证码
     *
     * 调用方若需投递，应使用 {@see self::send()}；仅在"自行投递"的定制场景下
     * 才单独调用本方法。
     */
    public function generate(string $scene, string $target): string
    {
        $length = max(4, min(8, (int) $this->option('length')));
        $code   = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        $ttl = (int) $this->option('ttl');

        Cache::set($this->codeKey($scene, $target), $code, $ttl);
        Cache::delete($this->attemptKey($scene, $target));

        // 频控：冷却窗口 + 当日累计
        Cache::set($this->cooldownKey($scene, $target), time(), (int) $this->option('cooldown'));

        $dayKey = $this->dayKey($scene, $target);
        Cache::set($dayKey, (int) Cache::get($dayKey, 0) + 1, $this->secondsUntilTomorrow());

        return $code;
    }

    /**
     * 校验验证码
     *
     * 成功即消费（删除）；失败累计次数，超限作废。
     *
     * @param string $scene  场景（sms / email / 业务自定义）
     * @param string $target 目标（手机号 / 邮箱）
     * @param string $code   用户提交的验证码
     */
    public function verify(string $scene, string $target, string $code): bool
    {
        $code   = trim($code);
        $stored = Cache::get($this->codeKey($scene, $target));

        if (!is_string($stored) || $stored === '' || $code === '') {
            return false;
        }

        $attempts = (int) Cache::get($this->attemptKey($scene, $target), 0) + 1;
        $maxTtl   = (int) $this->option('ttl');

        if ($attempts > (int) $this->option('max_attempts')) {
            // 超限：作废验证码，需重新获取
            $this->forget($scene, $target);

            return false;
        }

        Cache::set($this->attemptKey($scene, $target), $attempts, $maxTtl);

        if (!hash_equals($stored, $code)) {
            return false;
        }

        $this->forget($scene, $target);

        return true;
    }

    /**
     * 发送频控检查
     *
     * @return array{allowed:bool, reason:string, retry_after:int}
     */
    public function canSend(string $scene, string $target): array
    {
        if (Cache::get($this->cooldownKey($scene, $target)) !== null) {
            $cooldown = (int) $this->option('cooldown');

            return [
                'allowed'     => false,
                'reason'      => "请求过于频繁，请 {$cooldown} 秒后再试",
                'retry_after' => $cooldown,
            ];
        }

        $sentToday = (int) Cache::get($this->dayKey($scene, $target), 0);
        $maxPerDay = (int) $this->option('max_per_day');

        if ($sentToday >= $maxPerDay) {
            return [
                'allowed'     => false,
                'reason'      => '今日验证码发送次数已达上限，请明日再试',
                'retry_after' => $this->secondsUntilTomorrow(),
            ];
        }

        return ['allowed' => true, 'reason' => '', 'retry_after' => 0];
    }

    /**
     * 生成 + 投递验证码
     *
     * @param string          $scene      场景（决定缓存命名与短信模板语义）
     * @param string          $target     目标（手机号 / 邮箱）
     * @param Notifiable|null $notifiable 可通知实体（通常为 Member）；为 null 时只生成不投递
     * @param string          $channel    投递通道（sms / email）
     * @return string 明文验证码（仅用于测试与日志排查，严禁返回给前端）
     * @throws \RuntimeException 频控拒绝或投递失败
     */
    public function send(string $scene, string $target, ?Notifiable $notifiable = null, string $channel = self::SCENE_SMS): string
    {
        $check = $this->canSend($scene, $target);

        if (!$check['allowed']) {
            throw new \RuntimeException($check['reason'], 429001);
        }

        $code = $this->generate($scene, $target);

        if ($notifiable === null) {
            return $code;
        }

        $result = $this->app->make(NotificationManager::class)
            ->channel($channel)
            ->sync()
            ->priority(Message::PRIORITY_URGENT)
            ->send($notifiable, [
                'title'   => '验证码',
                'content' => $this->renderMessage($code),
                // 短信 / 邮件模板参数（模板中可用 {code} 占位）
                'code'    => $code,
            ]);

        // send() 可能返回 null（无有效结果）或 BatchSendResult（多通道），
        // 只判断 SendResult 会把这两种情况下的投递失败误判为成功：
        // 验证码已生成、冷却与当日计数已扣，用户却永远收不到
        $failed = $result instanceof BatchSendResult
            ? $result->failedCount > 0
            : ($result === null || !$result->success);

        if ($failed) {
            // 投递失败：作废刚生成的验证码，避免"用户没收到但码已消耗冷却"的尴尬
            $this->forget($scene, $target);
            Cache::delete($this->cooldownKey($scene, $target));
            Cache::delete($this->dayKey($scene, $target));

            throw new \RuntimeException(
                '验证码发送失败：' . ($result instanceof SendResult ? ($result->error ?: '渠道未正确配置') : '渠道未正确配置'),
                500003
            );
        }

        return $code;
    }

    /**
     * 主动作废某目标的验证码与失败计数（不清理频控窗口）
     */
    public function forget(string $scene, string $target): void
    {
        Cache::delete($this->codeKey($scene, $target));
        Cache::delete($this->attemptKey($scene, $target));
    }

    /**
     * 渲染验证码文本（模板占位：{site} / {code} / {minutes}）
     */
    public function renderMessage(string $code): string
    {
        $template = (string) $this->option('template');
        $site     = (string) (config('wise-member.base.site_name') ?: config('app.app_name') ?: '本站');
        $minutes  = (int) ceil(((int) $this->option('ttl')) / 60);

        return str_replace(
            ['{site}', '{code}', '{minutes}'],
            [$site, $code, (string) $minutes],
            $template
        );
    }

    /**
     * 读取策略配置（config('wise-notification.code')）
     */
    protected function option(string $key): mixed
    {
        $code  = config('wise-notification.code');
        $value = is_array($code) ? ($code[$key] ?? null) : null;

        return $value ?? self::DEFAULTS[$key] ?? null;
    }

    protected function codeKey(string $scene, string $target): string
    {
        return "wise:notify:code:{$scene}:{$target}";
    }

    protected function attemptKey(string $scene, string $target): string
    {
        return "wise:notify:code_try:{$scene}:{$target}";
    }

    protected function cooldownKey(string $scene, string $target): string
    {
        return "wise:notify:code_cd:{$scene}:{$target}";
    }

    protected function dayKey(string $scene, string $target): string
    {
        return "wise:notify:code_day:{$scene}:{$target}:" . date('Ymd');
    }

    /**
     * 距离今日 24:00 的剩余秒数（用作日计数 TTL）
     */
    protected function secondsUntilTomorrow(): int
    {
        return max(1, strtotime('tomorrow') - time());
    }
}
