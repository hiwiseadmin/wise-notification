<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;
use wise\notification\security\DataMasker;

/**
 * NotificationHistory — 发送历史模型
 *
 * 对应 notification_histories 表，记录每次通知发送的完整信息。
 * 不自动写入时间戳（表仅含 create_time）。
 *
 * 安全：所有写入（record）都会经 maskSensitive() 强制脱敏，
 * 作为敏感数据落库的最后一道防线，调用方无法绕过。
 */
class NotificationHistory extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'notification_histories';

    /**
     * 禁用自动时间戳（表仅含 create_time）
     *
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    /**
     * 快速创建发送记录
     *
     * @param array $data 记录数据
     * @return static
     */
    public static function record(array $data): self
    {
        $record = new static();
        $record->save(self::sanitize($data));
        return $record;
    }

    /**
     * 安全 JSON 编码
     *
     * 使用 JSON_INVALID_UTF8_SUBSTITUTE，非法 UTF-8 字节被替换而非导致
     * json_encode 返回 false（原实现会静默写入空 payload）。
     */
    public static function encodePayload(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * 落库前统一净化：脱敏 + 长度截断（最后一道防线）
     *
     * 覆盖 message_payload（JSON 结构化脱敏）、response、error 三个字段：
     * - 脱敏：避免验证码 / 手机号 / 邮箱 / Token 等敏感数据明文入库，
     *   也覆盖未经过 NotificationManager 的直接写入（如宿主业务层）；
     * - 截断：避免超长异常栈 / 第三方响应撑爆 TEXT 字段导致写入失败。
     *
     * @param array $data 待写入数据
     * @return array 净化后的数据
     */
    public static function sanitize(array $data): array
    {
        $maxBytes = (int) config('wise-notification.history_max_payload_bytes', 65000);
        $masker   = new DataMasker();
        $hasRules = $masker->hasRules();

        // message_payload 为 JSON 字符串：解码后按字段名脱敏，再编码回写
        if (isset($data['message_payload']) && is_string($data['message_payload'])) {
            if ($hasRules) {
                $decoded = json_decode($data['message_payload'], true);
                if (is_array($decoded)) {
                    $data['message_payload'] = self::encodePayload($masker->mask($decoded));
                }
            }
        }

        // response / error 为自由文本：按规则自动识别脱敏
        if ($hasRules) {
            foreach (['response', 'error'] as $field) {
                if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                    $data[$field] = $masker->maskString($data[$field]);
                }
            }
        }

        // 长度截断
        if ($maxBytes > 0) {
            foreach (['message_payload', 'response', 'error'] as $field) {
                if (isset($data[$field]) && is_string($data[$field]) && strlen($data[$field]) > $maxBytes) {
                    $data[$field] = self::cutBytes($data[$field], $maxBytes) . '...[truncated]';
                }
            }
        }

        return $data;
    }

    /**
     * 按字节数安全截断（不切断多字节字符）
     */
    private static function cutBytes(string $value, int $maxBytes): string
    {
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return substr($value, 0, $maxBytes);
    }

    /**
     * 查询作用域：按通道筛选
     *
     * @param \think\db\Query $query
     * @param string          $channel 通道名称
     * @return void
     */
    public function scopeByChannel($query, string $channel): void
    {
        $query->where('channel', '=', $channel);
    }

    /**
     * 查询作用域：按状态筛选
     *
     * @param \think\db\Query $query
     * @param string          $status 状态值：sent/failed/pending
     * @return void
     */
    public function scopeByStatus($query, string $status): void
    {
        $query->where('status', '=', $status);
    }

    /**
     * 查询作用域：按接收者筛选
     *
     * @param \think\db\Query $query
     * @param string          $type 接收者类型（模型类名）
     * @param string|int      $id   接收者 ID
     * @return void
     */
    public function scopeByNotifiable($query, string $type, string|int $id): void
    {
        $query->where('notifiable_type', '=', $type)
            ->where('notifiable_id', '=', (string) $id);
    }
}
