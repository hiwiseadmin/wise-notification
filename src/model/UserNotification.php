<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;

/**
 * UserNotification — 站内信模型
 *
 * 对应 user_notifications 表，存储用户级别的站内通知，
 * 支持已读/未读状态管理。
 */
class UserNotification extends Model
{
    /**
     * 表名（不含前缀，框架自动添加）
     *
     * @var string
     */
    protected $name = 'user_notifications';

    /**
     * 禁用自动时间戳（表仅含 create_time，read_at 手动管理）
     *
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    /**
     * 标记为已读
     *
     * 设置 is_read = 1，read_at = 当前时间。
     *
     * @return bool
     */
    public function markAsRead(): bool
    {
        $this->setAttr('is_read', 1);
        $this->setAttr('read_at', date('Y-m-d H:i:s'));
        return $this->save();
    }

    /**
     * 查询作用域：仅查询未读记录
     *
     * @param \think\db\Query $query
     * @return void
     */
    public function scopeUnread($query): void
    {
        $query->where('is_read', '=', 0);
    }

    /**
     * 查询作用域：按用户 ID 筛选
     *
     * @param \think\db\Query $query
     * @param int             $userId 用户 ID
     * @return void
     */
    public function scopeByUser($query, int $userId): void
    {
        $query->where('user_id', '=', $userId);
    }
}
