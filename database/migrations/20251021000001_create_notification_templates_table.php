<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 消息模板表迁移
 *
 * 用于存储可复用的消息模板，支持多语言（同一 code + 不同 locale 分多行）。
 */
final class CreateNotificationTemplatesTable extends Migrator
{
    protected function getTable(): string
    {
        return 'notification_templates';
    }

    /**
     * 执行迁移
     */
    public function up(): void
    {
        $table = $this->table($this->getTable(), [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '消息模板表',
        ]);

        $table->addColumn(Column::string('code', 64)->setNull(false)->setComment('模板唯一标识，如 welcome_email'))
            ->addColumn(Column::string('name', 128)->setNull(false)->setComment('模板名称'))
            ->addColumn(Column::string('channel', 32)->setNull(false)->setComment('适用通道：email/sms/database/dingtalk'))
            ->addColumn(Column::string('locale', 16)->setDefault('zh_CN')->setNull(false)->setComment('语言标识'))
            ->addColumn(Column::string('title', 256)->setNull(false)->setDefault('')->setComment('标题模板（含变量占位符）'))
            ->addColumn(Column::text('content')->setNull(false)->setComment('正文模板（含变量占位符）'))
            ->addColumn(Column::text('variables')->setNull(true)->setComment('预定义变量列表及说明（JSON）'))
            ->addColumn(Column::tinyInteger('status')->setLimit(1)->setSigned(false)->setDefault(1)->setComment('0=禁用 1=启用'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            // ↓ 已修正：唯一索引直接使用 code+locale+channel（合并 FixNotificationTemplatesUniqueIndex）
            ->addIndex(['code', 'locale', 'channel'], ['unique' => true, 'name' => 'idx_code_locale_channel'])
            ->addIndex(['channel'], ['name' => 'idx_channel'])
            ->addIndex(['status'], ['name' => 'idx_status'])
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
