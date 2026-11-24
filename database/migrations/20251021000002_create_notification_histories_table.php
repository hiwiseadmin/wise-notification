<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 发送历史表迁移
 *
 * 记录每次通知发送的完整信息：通道、状态、响应、错误、耗时。
 */
final class CreateNotificationHistoriesTable extends Migrator
{
    protected function getTable(): string
    {
        return 'notification_histories';
    }

    /**
     * 执行迁移
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '发送历史表',
        ]);

        $table->addColumn(Column::string('channel', 32)->setNull(false)->setComment('发送通道：email/sms/database/dingtalk'))
            ->addColumn(Column::string('notifiable_type', 128)->setNull(false)->setComment('接收者类型（模型类名）'))
            ->addColumn(Column::string('notifiable_id', 64)->setNull(false)->setComment('接收者 ID'))
            ->addColumn(Column::string('message_type', 128)->setNull(true)->setDefault('direct')->setComment('消息类型/模板 code'))
            ->addColumn(Column::text('message_payload')->setNull(true)->setComment('消息内容快照（JSON）'))
            ->addColumn(Column::string('status', 16)->setNull(false)->setDefault('pending')->setComment('发送状态：sent/failed/pending'))
            ->addColumn(Column::text('response')->setNull(true)->setComment('通道返回的原始响应'))
            ->addColumn(Column::text('error')->setNull(true)->setComment('错误信息（失败时）'))
            ->addColumn(Column::integer('duration_ms')->setSigned(false)->setNull(true)->setDefault(0)->setComment('发送耗时（毫秒）'))
            ->addColumn(Column::string('batch_id', 64)->setNull(true)->setComment('批次ID'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('发送时间'))
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['notifiable_type', 'notifiable_id'], ['name' => 'idx_notifiable'])
            ->addIndex(['batch_id'], ['name' => 'idx_batch_id'])
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
