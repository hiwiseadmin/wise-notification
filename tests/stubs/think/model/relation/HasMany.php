<?php

declare(strict_types=1);

namespace think\model\relation;

/**
 * ThinkPHP HasMany 关系桩
 */
class HasMany
{
    private string $model;
    private string $foreignKey;

    public function __construct(string $model, string $foreignKey)
    {
        $this->model = $model;
        $this->foreignKey = $foreignKey;
    }
}
