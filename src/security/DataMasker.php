<?php

declare(strict_types=1);

namespace wise\notification\security;

/**
 * DataMasker — 敏感字段脱敏器
 *
 * 对通知内容中的敏感信息（手机、邮箱、验证码、Token 等）进行脱敏处理。
 * 用于发送历史中的 message_payload / response / error。
 *
 * 两套规则（配置来源可分别覆盖）
 * ==============================
 * - `audit.mask_fields`  按**字段名**匹配（{@see mask()}，递归），
 *   包含整字段替换规则（验证码/密码/Token 等 key 命中即替换）。
 * - `audit.mask_content` 用于**自由文本**自动识别（{@see maskString()}），
 *   只放局部遮蔽模式（手机/邮箱），避免整字段替换规则把任意非空文本变成 `***`。
 *
 * 使用位置：
 * - NotificationHistory::sanitize()（落库最后一道防线，覆盖 record() 与更新路径）
 */
class DataMasker
{
    /** @var array<string, array{pattern: string, replacement: string}> 字段名规则 */
    private array $rules;

    /** @var array<string, array{pattern: string, replacement: string}> 自由文本规则 */
    private array $contentRules;

    public function __construct(?array $rules = null, ?array $contentRules = null)
    {
        $this->rules        = $rules ?? (array) config('wise-notification.audit.mask_fields', []);
        $this->contentRules = $contentRules ?? (array) config('wise-notification.audit.mask_content', []);
    }

    /**
     * 是否配置了有效脱敏规则
     */
    public function hasRules(): bool
    {
        return $this->rules !== [] || $this->contentRules !== [];
    }

    /**
     * 脱敏数组中的所有敏感字段（递归，按键名匹配）
     */
    public function mask(array $data): array
    {
        return $this->maskRecursive($data);
    }

    /**
     * 脱敏单个字符串
     *
     * 指定 $fieldType 且存在同名字段规则时按其脱敏；
     * 否则用自由文本规则做自动识别（命中第一条产生变化的规则即返回）。
     */
    public function maskString(string $value, string $fieldType = 'default'): string
    {
        if (isset($this->rules[$fieldType]['pattern'])) {
            return $this->apply($value, $this->rules[$fieldType]);
        }

        foreach ($this->contentRules as $rule) {
            if (!is_array($rule) || !isset($rule['pattern'], $rule['replacement'])) {
                continue;
            }
            $masked = $this->apply($value, $rule);
            if ($masked !== $value) {
                return $masked;
            }
        }

        return $value;
    }

    /**
     * 添加自定义字段脱敏规则
     */
    public function addRule(string $field, string $pattern, string $replacement): void
    {
        $this->rules[$field] = ['pattern' => $pattern, 'replacement' => $replacement];
    }

    // ==================== 私有方法 ====================

    /**
     * 执行单条规则；非法正则（preg_replace 返回 null）时保留原值
     */
    private function apply(string $value, array $rule): string
    {
        if (!isset($rule['pattern'], $rule['replacement'])) {
            return $value;
        }

        $result = @preg_replace((string) $rule['pattern'], (string) $rule['replacement'], $value);

        return $result ?? $value;
    }

    private function maskRecursive(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // 检查 key 是否为敏感字段：标量值统一先转字符串再套规则，
                // 避免 int/float/bool 形式（如 'code' => 123456）绕过整字段替换规则明文落库
                if (is_string($key) && isset($this->rules[$key]['pattern']) && is_scalar($value)) {
                    $result[$key] = $this->apply((string) $value, $this->rules[$key]);
                } else {
                    $result[$key] = $this->maskRecursive($value);
                }
            }
            return $result;
        }

        return $data;
    }
}
