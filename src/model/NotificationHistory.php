<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;

/**
 * NotificationHistory — 发送历史模型
 *
 * 对应 notification_histories 表，记录每次通知发送的完整信息。
 * 不自动写入时间戳（表仅含 create_time）。
 */
class NotificationHistory extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'notification_histories';

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-notification.table_names.histories', $this->name));
    }

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
        $record->save($data);
        return $record;
    }

    /**
     * 按批次 ID 批量更新记录
     *
     * 用于队列任务完成后批量更新同一批次内所有 pending 记录的状态。
     *
     * @param string $batchId 批次 ID
     * @param array  $data    要更新的字段，如 ['status' => 'sent', 'response' => '...']
     * @return int 受影响的行数
     */
    public static function updateByBatchId(string $batchId, array $data): int
    {
        return (new static())
            ->where('batch_id', '=', $batchId)
            ->where('status', '=', 'pending')
            ->update($data);
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
