<?php

declare(strict_types=1);

namespace wise\notification\security;

/**
 * DataMasker — 敏感字段脱敏器
 *
 * 对通知内容中的敏感信息（手机、邮箱、OpenID 等）进行脱敏处理。
 * 用于审计日志和发送历史中的 message_payload。
 */
class DataMasker
{
    /** @var array<string, array{pattern: string, replacement: string}> */
    private array $rules;

    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? config('wise-notification.audit.mask_fields', []);
    }

    /**
     * 脱敏数组中的所有敏感字段
     */
    public function mask(array $data): array
    {
        return $this->maskRecursive($data);
    }

    /**
     * 脱敏单个字符串
     */
    public function maskString(string $value, string $fieldType = 'default'): string
    {
        if (isset($this->rules[$fieldType])) {
            return preg_replace(
                $this->rules[$fieldType]['pattern'],
                $this->rules[$fieldType]['replacement'],
                $value
            );
        }

        // 自动检测
        foreach ($this->rules as $rule) {
            $masked = preg_replace($rule['pattern'], $rule['replacement'], $value);
            if ($masked !== $value) {
                return $masked;
            }
        }

        return $value;
    }

    /**
     * 添加自定义脱敏规则
     */
    public function addRule(string $field, string $pattern, string $replacement): void
    {
        $this->rules[$field] = ['pattern' => $pattern, 'replacement' => $replacement];
    }

    // ==================== 私有方法 ====================

    private function maskRecursive(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // 检查 key 是否为敏感字段
                if (is_string($key) && isset($this->rules[$key]) && is_string($value)) {
                    $result[$key] = preg_replace(
                        $this->rules[$key]['pattern'],
                        $this->rules[$key]['replacement'],
                        $value
                    );
                } else {
                    $result[$key] = $this->maskRecursive($value);
                }
            }
            return $result;
        }

        return $data;
    }
}
