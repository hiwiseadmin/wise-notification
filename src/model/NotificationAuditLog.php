<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;

/**
 * NotificationAuditLog — 通知审计日志模型
 *
 * 对应 notification_audit_logs 表。
 * 记录每条通知的完整操作链路。
 */
class NotificationAuditLog extends Model
{
    protected $name = 'notification_audit_logs';

    /** 自动时间戳 */
    protected $autoWriteTimestamp = false;

    /** 快捷创建审计记录 */
    public static function log(array $data): self
    {
        $data['create_time'] = $data['create_time'] ?? date('Y-m-d H:i:s');
        return static::create($data);
    }

    /** 按操作筛选 */
    public function scopeByAction($query, string $action): void
    {
        $query->where('action', '=', $action);
    }

    /** 按状态筛选 */
    public function scopeByStatus($query, string $status): void
    {
        $query->where('status', '=', $status);
    }

    /** 按时间范围筛选 */
    public function scopeInDateRange($query, string $start, string $end): void
    {
        $query->whereBetween('create_time', [$start, $end]);
    }

    /** 按通道筛选 */
    public function scopeByChannel($query, string $channel): void
    {
        $query->where('channel', '=', $channel);
    }

    /**
     * 获取指定时间段内失败率
     */
    public static function getFailureRate(string $start, string $end, ?string $channel = null): array
    {
        $query = static::inDateRange($start, $end);
        if ($channel !== null) {
            $query->byChannel($channel);
        }

        $total = $query->count();
        $failed = (clone $query)->byStatus('failed')->count();

        return [
            'total'       => $total,
            'failed'      => $failed,
            'success'     => $total - $failed,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
        ];
    }
}
