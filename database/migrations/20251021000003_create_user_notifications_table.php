<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 站内信表迁移
 *
 * 存储用户级别的站内通知，支持已读/未读状态管理。
 */
final class CreateUserNotificationsTable extends Migrator
{
    protected function getTable(): string
    {
        return 'user_notifications';
    }

    /**
     * 执行迁移
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '站内信表',
        ]);

        $table->addColumn(Column::integer('user_id')->setSigned(false)->setNull(false)->setComment('用户 ID'))
            ->addColumn(Column::string('title', 256)->setNull(false)->setDefault('')->setComment('消息标题'))
            ->addColumn(Column::text('content')->setNull(false)->setComment('消息内容'))
            ->addColumn(Column::string('type', 32)->setNull(true)->setDefault('system')->setComment('消息类型'))
            ->addColumn(Column::text('data')->setNull(true)->setComment('附加数据（跳转链接等）（JSON）'))
            ->addColumn(Column::tinyInteger('is_read')->setLimit(1)->setSigned(false)->setDefault(0)->setComment('0=未读 1=已读'))
            ->addColumn(Column::dateTime('read_at')->setNull(true)->setComment('阅读时间'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addIndex(['user_id'], ['name' => 'idx_user_id'])
            ->addIndex(['is_read'], ['name' => 'idx_is_read'])
            ->create();
    }

    /**
     * 回滚迁移
     */
    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
