<?php

declare(strict_types=1);

namespace think\db;

/**
 * ThinkPHP Query 桩
 */
class Query
{
    /** @var array 查询条件 */
    private array $wheres = [];
    /** @var array 排序 */
    private array $orders = [];

    /**
     * 添加 WHERE 条件
     */
    public function where(string $field, string $operator = '', mixed $value = null): self
    {
        $this->wheres[] = [$field, $operator, $value];
        return $this;
    }

    /**
     * 添加排序
     */
    public function order(string $field, string $direction = 'asc'): self
    {
        $this->orders[] = [$field, $direction];
        return $this;
    }

    /**
     * 查找单条
     */
    public function find(): mixed
    {
        return null;
    }
}
