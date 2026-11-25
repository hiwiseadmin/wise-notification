<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 通知偏好表迁移
 *
 * 存储用户级别的通知偏好：通道开关、免打扰时段、每日上限、类型订阅。
 */
final class CreateNotificationPreferencesTable extends Migrator
{
    protected function getTable(): string
    {
        return 'notification_preferences';
    }

    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '通知偏好表',
        ]);

        $table->addColumn(Column::string('notifiable_type', 128)->setNull(false)->setComment('接收者类型（模型类名）'))
            ->addColumn(Column::string('notifiable_id', 64)->setNull(false)->setComment('接收者ID'))
            ->addColumn(Column::string('channel', 32)->setNull(false)->setComment('通道名称'))
            ->addColumn(Column::tinyInteger('enabled')->setLimit(1)->setSigned(false)->setDefault(1)->setComment('通道开关 0=关闭 1=开启'))
            ->addColumn(Column::tinyInteger('daily_limit')->setNull(true)->setComment('每日上限（null=使用全局配置）'))
            ->addColumn(Column::string('quiet_start', 8)->setNull(true)->setComment('免打扰开始时间 HH:MM'))
            ->addColumn(Column::string('quiet_end', 8)->setNull(true)->setComment('免打扰结束时间 HH:MM'))
            ->addColumn(Column::text('subscribed_types')->setNull(true)->setComment('订阅的消息类型（JSON数组，null=全部）'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['notifiable_type', 'notifiable_id'], ['name' => 'idx_notifiable'])
            ->addIndex(['notifiable_type', 'notifiable_id', 'channel'], ['unique' => true, 'name' => 'idx_notifiable_channel'])
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable($this->getTable())) {
            $this->table($this->getTable())->drop()->save();
        }
    }
}
