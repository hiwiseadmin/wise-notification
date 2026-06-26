<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 通知审计日志表迁移
 *
 * 记录完整的发送链路：谁、何时、通过什么通道、给谁发了什么。
 * message_payload 中的敏感字段已脱敏。
 */
final class CreateNotificationAuditLogsTable extends Migrator
{
    protected function getTable(): string
    {
        return config('wise-notification.table_names.audit_logs', 'notification_audit_logs');
    }

    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '通知审计日志表',
        ]);

        $table->addColumn(Column::char('action', 16)->setNull(false)->setComment('操作：send/batch/delay/throttle/retry/dead'))
            ->addColumn(Column::string('channel', 32)->setNull(false)->setComment('通道名称'))
            ->addColumn(Column::string('notifiable_type', 128)->setNull(false)->setComment('接收者类型'))
            ->addColumn(Column::string('notifiable_id', 64)->setNull(false)->setComment('接收者ID'))
            ->addColumn(Column::string('message_type', 32)->setNull(true)->setComment('消息类型：direct/template'))
            ->addColumn(Column::text('message_payload')->setNull(true)->setComment('消息内容（敏感字段已脱敏）'))
            ->addColumn(Column::string('status', 16)->setNull(false)->setDefault('pending')->setComment('状态：pending/sent/failed/dead'))
            ->addColumn(Column::text('error')->setNull(true)->setComment('错误信息'))
            ->addColumn(Column::integer('duration_ms')->setNull(true)->setComment('耗时（毫秒）'))
            ->addColumn(Column::text('context')->setNull(true)->setComment('上下文（IP、UA、来源模块等JSON）'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addIndex(['action', 'create_time'], ['name' => 'idx_action_time'])
            ->addIndex(['channel', 'create_time'], ['name' => 'idx_channel_time'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_status_time'])
            ->addIndex(['notifiable_type', 'notifiable_id'], ['name' => 'idx_notifiable'])
            ->create();
    }

    public function down(): void
    {
        $this->table($this->getTable())->drop();
    }
}
