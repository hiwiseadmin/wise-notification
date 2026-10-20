<?php

declare(strict_types=1);

namespace wise\notification\template;

/**
 * TemplateCompiler — 模板编译器
 *
 * 不引入第三方渲染引擎，自研轻量模板系统。支持：
 *
 * 语法规范
 * ========
 *
 * 变量输出（默认 HTML 转义）:
 *   {var}              — 简单变量
 *   {user.name}        — 嵌套访问（点号 + 数组访问）
 *   {items.0}          — 数组索引访问
 *
 * 变量输出 — 默认值:
 *   {var|默认文本}      — 变量为空时使用默认值
 *
 * 原始输出（不转义 HTML）:
 *   {!var!}            — 输出原始 HTML（需 template.allow_raw_output=true 才生效；
 *                        关闭时降级为 HTML 转义输出，避免存储型 XSS）
 *
 * 条件判断:
 *   {% if var %}...{% endif %}
 *   {% if var %}...{% else %}...{% endif %}
 *   {% if !var %}...{% endif %}         — 取反
 *   {% if var == "value" %}...{% endif %} — 等值比较
 *   {% if var != "value" %}...{% endif %} — 不等比较
 *
 * 循环:
 *   {% for item in items %}...{% endfor %}
 *   循环内可用变量: {item} / {item.key}（数组key）/ {_index}（0-based计数器）
 *
 * 变量校验（模板定义 variables JSON）:
 *   {"name": {"required": true, "type": "string", "default": "用户"}}
 *
 * 引擎模式
 * ========
 * - simple: 仅支持 {var} 变量替换（向后兼容）
 * - extended: 支持全部语法
 */
class TemplateCompiler
{
    /** 引擎模式 */
    private string $mode;

    /** 是否 HTML 转义 */
    private bool $escapeHtml;

    /** 模板定义中的变量元信息 */
    private ?array $variableMeta;

    /** 是否允许读取对象属性 / getter（安全开关，默认关闭） */
    private bool $allowObjectAccess;

    /** 是否允许 {!var!} 原样输出未转义 HTML（安全开关，默认关闭） */
    private bool $allowRawOutput;

    /**
     * @param string $mode              引擎模式（simple|extended）
     * @param bool   $escapeHtml        是否默认 HTML 转义
     * @param ?array $variableMeta      变量元信息
     * @param bool   $allowObjectAccess 是否允许对象取值（默认 false，防越权读取对象内部字段）
     * @param bool   $allowRawOutput    是否允许 {!var!} 原始输出（默认 false，关闭时降级为转义输出）
     */
    public function __construct(
        string $mode = 'extended',
        bool $escapeHtml = true,
        ?array $variableMeta = null,
        bool $allowObjectAccess = false,
        bool $allowRawOutput = false,
    ) {
        $this->mode = $mode;
        $this->escapeHtml = $escapeHtml;
        $this->variableMeta = $variableMeta;
        $this->allowObjectAccess = $allowObjectAccess;
        $this->allowRawOutput = $allowRawOutput;
    }

    /**
     * 渲染模板
     *
     * @param string $template  模板字符串
     * @param array  $variables 变量键值对
     * @return string 渲染后的内容
     */
    public function render(string $template, array $variables): string
    {
        // 使用局部对象持有运行时状态，确保实例可重入
        $state = (object) [
            'variables'   => $variables,
            'loopIndices' => [],
        ];

        // 变量校验
        if ($this->variableMeta !== null) {
            $this->validateVariables($variables);
        }

        // 按顺序处理：先处理 for 循环块，再处理 if 条件块，最后替换变量
        if ($this->mode === 'extended') {
            $template = $this->processForLoops($template, $state);
            $template = $this->processIfConditions($template, $state);
        }

        return $this->replaceVariables($template, $state);
    }

    /**
     * 预览模板变量（不实际渲染，返回模板中引用的变量列表）
     *
     * @param string $template 模板字符串
     * @return array{simple: array, nested: array, conditions: array, loops: array}
     */
    public function analyze(string $template): array
    {
        $result = [
            'simple'     => [],
            'nested'     => [],
            'conditions'  => [],
            'loops'       => [],
        ];

        // 分析简单变量 {var} 和默认值 {var|default}
        preg_match_all('/\{(\w+(?:\.\w+)*)(?:\|([^}]*))?\}/', $template, $vars);
        foreach ($vars[1] as $i => $var) {
            $result['simple'][] = $var;
            if (!empty($vars[2][$i])) {
                $result['simple'][] = "{$var}|{$vars[2][$i]}";
            }
        }

        // 分析原始输出 {!var!}
        preg_match_all('/\{!(\w+(?:\.\w+)*)!\}/', $template, $rawVars);
        foreach ($rawVars[1] as $var) {
            $result['nested'][] = "raw:{$var}";
        }

        // 分析条件
        preg_match_all('/\{%\s*if\s+(!?\w+(?:\s*[!=]=\s*"[^"]*")?)\s*%\}/', $template, $conds);
        $result['conditions'] = array_unique($conds[1]);

        // 分析循环
        preg_match_all('/\{%\s*for\s+(\w+)\s+in\s+(\w+(?:\.\w+)*)\s*%\}/', $template, $loops);
        foreach ($loops[1] as $idx => $item) {
            $result['loops'][] = "{$item} in {$loops[2][$idx]}";
        }

        // 去重
        $result['simple'] = array_unique($result['simple']);
        $result['nested'] = array_unique($result['nested']);

        return $result;
    }

    // ==================== 处理循环 ====================

    /**
     * 处理 {% for item in items %}...{% endfor %}
     */
    private function processForLoops(string $template, object $state): string
    {
        $pattern = '/\{%\s*for\s+(\w+)\s+in\s+([\w.]+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s';

        return preg_replace_callback($pattern, function (array $matches) use ($state): string {
            $itemName = $matches[1];
            $listKey  = $matches[2];
            $body     = $matches[3];
            $list     = $this->getNestedValue($listKey, $state);

            if (!is_array($list) || empty($list)) {
                return '';
            }

            $output = '';
            $state->loopIndices[] = 0;

            foreach ($list as $idx => $item) {
                // 注入循环变量
                $localVars = $state->variables;
                $localVars[$itemName] = $item;
                $localVars['_index']  = $idx;

                $savedVars = $state->variables;
                $state->variables = $localVars;

                // 递归处理循环体内的语法
                $renderedBody = $this->processIfConditions($body, $state);
                $renderedBody = $this->replaceVariables($renderedBody, $state);
                $output .= $renderedBody;

                $state->variables = $savedVars;
            }

            array_pop($state->loopIndices);

            return $output;
        }, $template);
    }

    // ==================== 处理条件 ====================

    /**
     * 处理 {% if expr %}...{% endif %} 和 {% if expr %}...{% else %}...{% endif %}
     *
     * 支持的表达式:
     * - {% if var %} — 变量为真时通过
     * - {% if !var %} — 变量为假时通过
     * - {% if var == "value" %} — 等值比较
     * - {% if var != "value" %} — 不等比较
     */
    private function processIfConditions(string $template, object $state): string
    {
        // 匹配 {% if ... %}...{% else %}...{% endif %}
        $withElse = '/\{%\s*if\s+([^%]+?)\s*%\}(.*?)\{%\s*else\s*%\}(.*?)\{%\s*endif\s*%\}/s';
        $template = preg_replace_callback($withElse, function (array $matches) use ($state): string {
            $condition = trim($matches[1]);
            $ifBranch  = $matches[2];
            $elseBranch = $matches[3];

            return $this->evaluateCondition($condition, $state)
                ? $this->processIfConditions($ifBranch, $state)
                : $this->processIfConditions($elseBranch, $state);
        }, $template);

        // 匹配 {% if ... %}...{% endif %}
        $withoutElse = '/\{%\s*if\s+([^%]+?)\s*%\}(.*?)\{%\s*endif\s*%\}/s';
        $template = preg_replace_callback($withoutElse, function (array $matches) use ($state): string {
            $condition = trim($matches[1]);
            $body      = $matches[2];

            return $this->evaluateCondition($condition, $state)
                ? $this->processIfConditions($body, $state)
                : '';
        }, $template);

        return $template;
    }

    /**
     * 求值条件表达式
     */
    private function evaluateCondition(string $expr, object $state): bool
    {
        // {% if !var %}
        if (preg_match('/^!(\w+(?:\.\w+)*)$/', $expr, $m)) {
            $value = $this->getNestedValue($m[1], $state);
            return !$this->isTruthy($value);
        }

        // {% if var == "value" %}
        if (preg_match('/^(\w+(?:\.\w+)*)\s*==\s*"([^"]*)"$/', $expr, $m)) {
            $value = $this->getNestedValue($m[1], $state);
            return (string) $value === $m[2];
        }

        // {% if var != "value" %}
        if (preg_match('/^(\w+(?:\.\w+)*)\s*!=\s*"([^"]*)"$/', $expr, $m)) {
            $value = $this->getNestedValue($m[1], $state);
            return (string) $value !== $m[2];
        }

        // {% if var %}
        $value = $this->getNestedValue($expr, $state);
        return $this->isTruthy($value);
    }

    // ==================== 变量替换 ====================

    /**
     * 替换模板中的变量占位符
     */
    private function replaceVariables(string $template, object $state): string
    {
        // 1. 先处理原始输出 {!var!}
        $template = preg_replace_callback(
            '/\{!(\w+(?:\.\w+)*(?:\.[\w.]+)*)!\}/',
            function (array $matches) use ($state): string {
                $value = $this->getNestedValue($matches[1], $state);

                if ($this->allowRawOutput) {
                    return (string) $value;
                }

                // 安全默认：关闭原始输出时降级为 HTML 转义，避免模板注入 HTML（存储型 XSS）
                return is_string($value)
                    ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                    : $this->formatValue($value);
            },
            $template
        );

        // 2. 再处理带默认值的 {var|default}
        $template = preg_replace_callback(
            '/\{(\w+(?:\.\w+)*(?:\.[\w.]+)*)\|([^}]*)\}/',
            function (array $matches) use ($state): string {
                $value = $this->getNestedValue($matches[1], $state);
                if ($value === null || $value === '' || $value === []) {
                    return $this->escapeHtml ? htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8') : $matches[2];
                }
                return $this->formatValue($value);
            },
            $template
        );

        // 3. 最后处理普通变量 {var}
        $template = preg_replace_callback(
            '/\{(\w+(?:\.\w+)*(?:\.[\w.]+)*)\}/',
            function (array $matches) use ($state): string {
                $value = $this->getNestedValue($matches[1], $state);
                if ($value === null) {
                    // 未提供变量且无默认值，检查元信息
                    if ($this->variableMeta !== null && isset($this->variableMeta[$matches[1]])) {
                        $meta = $this->variableMeta[$matches[1]];
                        if (isset($meta['default'])) {
                            return $this->escapeHtml
                                ? htmlspecialchars((string) $meta['default'], ENT_QUOTES, 'UTF-8')
                                : (string) $meta['default'];
                        }
                    }
                    return '';
                }
                return $this->formatValue($value);
            },
            $template
        );

        return $template;
    }

    // ==================== 工具方法 ====================

    /**
     * 获取嵌套变量值（支持 user.name / items.0.name 等点号路径）
     */
    private function getNestedValue(string $path, object $state): mixed
    {
        $parts = explode('.', $path);
        $value = $state->variables;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && $this->allowObjectAccess) {
                // 支持对象属性访问（默认关闭，仅在 allow_object_access=true 时启用）
                if (isset($value->{$part})) {
                    $value = $value->{$part};
                } elseif (method_exists($value, 'get' . ucfirst($part))) {
                    $value = $value->{'get' . ucfirst($part)}();
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * 格式化值为字符串
     */
    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->escapeHtml
                ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                : $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * 值的布尔判定（用于 if 条件）
     */
    private function isTruthy(mixed $value): bool
    {
        if ($value === null) return false;
        if ($value === false) return false;
        if ($value === '') return false;
        if ($value === 0) return false;
        if ($value === '0') return false;
        if (is_array($value) && empty($value)) return false;
        return true;
    }

    /**
     * 变量校验（基于模板定义的 variables JSON 元信息）
     */
    private function validateVariables(array $variables): void
    {
        foreach ($this->variableMeta as $name => $meta) {
            if (!is_array($meta)) continue;

            $required = $meta['required'] ?? false;
            $type = $meta['type'] ?? null;

            if ($required && !array_key_exists($name, $variables)) {
                throw new \RuntimeException(
                    "模板变量 \"{$name}\" 为必填项，但未提供"
                );
            }

            if ($type !== null && array_key_exists($name, $variables)) {
                $this->checkType($name, $variables[$name], $type);
            }
        }
    }

    /**
     * 类型校验
     */
    private function checkType(string $name, mixed $value, string $type): void
    {
        $valid = match ($type) {
            'string'  => is_string($value),
            'int'     => is_int($value),
            'float'   => is_float($value) || is_int($value),
            'bool'    => is_bool($value),
            'array'   => is_array($value),
            'numeric' => is_numeric($value),
            default   => true,
        };

        if (!$valid) {
            $actual = gettype($value);
            throw new \RuntimeException(
                "模板变量 \"{$name}\" 类型应为 {$type}，实际为 {$actual}"
            );
        }
    }
}
