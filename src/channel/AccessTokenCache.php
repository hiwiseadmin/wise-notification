<?php

declare(strict_types=1);

namespace wise\notification\channel;

use think\facade\Cache;
use wise\support\lock\DistributedLock;

/**
 * AccessTokenCache — 第三方 access_token 共享缓存
 *
 * 企业微信 / 微信公众号等通道需要 access_token，原实现把 token 存在
 * **进程内静态数组**，导致：
 * - 多 worker / 多机部署时每个进程各自请求平台接口，容易触发平台频率限制（如 45009）；
 * - 进程重启即丢失，token 反复刷新。
 *
 * 本类改为「共享缓存 + 并发刷新保护 + 失败降级」：
 * - 共享缓存：token 写入 Cache（生产建议 redis），所有进程共用同一 token；
 * - 并发保护：复用 wise-support {@see DistributedLock}，同一缓存键只放行一个刷新请求，
 *   其余进程等锁后 double-check 复用结果；
 * - 失败降级：缓存或锁不可用时直接请求平台接口，保证可用性（不因缓存故障阻断发送）。
 */
final class AccessTokenCache
{
    /** 缓存提前过期秒数，避免边界过期导致实际调用失败 */
    private const EXPIRY_SKEW_SECONDS = 300;

    /**
     * 读取或刷新 access_token
     *
     * @param string   $cacheKey 缓存键（需包含 appid / corpid 以保证隔离）
     * @param callable $refresh  刷新回调，返回 ['token' => string, 'expires_in' => int]
     * @return string access_token
     * @throws \RuntimeException 平台接口返回失败时抛出
     */
    public static function remember(string $cacheKey, callable $refresh): string
    {
        $cached = self::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $refreshOnce = function () use ($cacheKey, $refresh): string {
            // double check：等锁期间可能已有其他进程写入新 token
            $again = self::get($cacheKey);
            if ($again !== null) {
                return $again;
            }

            $result = (array) $refresh();
            $token  = (string) ($result['token'] ?? '');
            if ($token === '') {
                throw new \RuntimeException('access_token 获取失败：平台返回为空');
            }

            self::set($cacheKey, $token, (int) ($result['expires_in'] ?? 7200));

            return $token;
        };

        try {
            $outcome = (new DistributedLock())->withLock($cacheKey . ':lock', 10, $refreshOnce, 3000);

            if (!empty($outcome['acquired']) && is_string($outcome['result'] ?? null) && $outcome['result'] !== '') {
                return $outcome['result'];
            }
        } catch (\Throwable $e) {
            // 锁不可用（如未配置 redis）或回调失败：降级为直连刷新，保证可用性
            error_log('[wise-notification] token 并发刷新锁不可用，降级为直连刷新: ' . $e->getMessage());
        }

        return $refreshOnce();
    }

    /**
     * 读取缓存中的 token（缓存不可用时返回 null）
     */
    private static function get(string $key): ?string
    {
        try {
            $value = Cache::get($key);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 写入 token（缓存不可用时静默跳过）
     */
    private static function set(string $key, string $token, int $expiresIn): void
    {
        try {
            Cache::set($key, $token, max(60, $expiresIn - self::EXPIRY_SKEW_SECONDS));
        } catch (\Throwable $e) {
            error_log('[wise-notification] access_token 写入缓存失败: ' . $e->getMessage());
        }
    }
}
