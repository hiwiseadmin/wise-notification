<?php

declare(strict_types=1);

namespace think\queue;

/**
 * ThinkPHP Queue Job 桩
 */
class Job
{
    /** @var bool 是否已删除 */
    private bool $deleted = false;

    /**
     * 删除队列任务
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * 检查是否已删除
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * 获取任务尝试次数
     */
    public function attempts(): int
    {
        return 1;
    }

    /**
     * 释放任务回队列
     */
    public function release(int $delay = 0): void
    {
    }
}
