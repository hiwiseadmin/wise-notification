<?php

declare(strict_types=1);

namespace think;

/**
 * ThinkPHP Model 桩
 *
 * 为测试环境提供一个最小化的 Model 实现。
 * 支持属性读写、save() 操作及 HasMany 关系定义。
 */
abstract class Model
{
    /** @var array<string, mixed> 属性数据 */
    protected array $data = [];

    /** @var string 表名 */
    protected $name = '';

    /** @var bool 是否自动写入时间戳 */
    protected $autoWriteTimestamp = true;

    /**
     * 构造函数
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * 获取属性值
     */
    public function getAttr(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /**
     * 设置属性值
     */
    public function setAttr(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * 保存数据
     */
    public function save(array $data = []): bool
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
        if (!isset($this->data['id'])) {
            $this->data['id'] = 1;
        }
        return true;
    }

    /**
     * 查找单条记录
     *
     * @param mixed $id
     * @return static|null
     */
    public function find($id = null): ?static
    {
        // 桩：无参数调用（scope chain 末尾）默认返回 $this
        // 有参数但为空则返回 null
        if ($id === null || $id === '') {
            // 如果是在 scope chain 之后被调用（即已经有查询条件），返回 $this
            return $this;
        }
        return $this;
    }

    /**
     * 定义 HasMany 关系
     */
    protected function hasMany(string $model, string $foreignKey = ''): \think\model\relation\HasMany
    {
        return new \think\model\relation\HasMany($model, $foreignKey);
    }

    /**
     * 查询作用域处理
     */
    public function __call(string $method, array $args): mixed
    {
        // 处理 scope 方法：scopeXxx → 调用并返回 $this
        if (str_starts_with($method, 'scope') && strlen($method) > 5) {
            $scopeMethod = lcfirst(substr($method, 5));
            if (method_exists($this, 'scope' . ucfirst($scopeMethod))) {
                // scope 方法已在外部通过 __callStatic 处理
                return $this;
            }
        }
        return $this;
    }

    /**
     * 静态调用处理：支持查询作用域
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $instance = new static();

        // 处理 scope 链式调用
        if (str_starts_with($method, 'scope') && strlen($method) > 5) {
            $scopeMethod = 'scope' . ucfirst(substr($method, 5));
            if (method_exists($instance, $scopeMethod)) {
                if (empty($args)) {
                    $instance->{$scopeMethod}($instance);
                } else {
                    $instance->{$scopeMethod}($instance, ...$args);
                }
            }
        }
        // 处理直接方法调用（如 active(), byCode() 等 scope 简写）
        elseif (method_exists($instance, 'scope' . ucfirst($method))) {
            $scopeMethod = 'scope' . ucfirst($method);
            if (empty($args)) {
                $instance->{$scopeMethod}($instance);
            } else {
                $instance->{$scopeMethod}($instance, ...$args);
            }
        }

        return $instance;
    }

    /**
     * 魔术方法：读取 data
     */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /**
     * 魔术方法：写入 data
     */
    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * 魔术方法：检查属性是否存在
     */
    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /**
     * 获取表名
     */
    public function getName(): string
    {
        return $this->name;
    }
}
