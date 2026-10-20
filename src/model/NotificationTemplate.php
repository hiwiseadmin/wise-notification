<?php

declare(strict_types=1);

namespace wise\notification\model;

use think\Model;
use wise\notification\contract\Message;
use wise\notification\template\TemplateCompiler;

/**
 * NotificationTemplate — 消息模板模型
 *
 * 对应 notification_templates 表，支持多语言（同一 code + 不同 locale 分多行）。
 *
 * 模板引擎:
 * - simple 模式: 仅支持 {var} 变量替换（向后兼容）
 * - extended 模式: 支持条件分支、循环、默认值、嵌套访问、原始输出
 */
class NotificationTemplate extends Model
{
    protected $name = 'notification_templates';

    public function scopeActive($query): void
    {
        $query->where('status', '=', 1);
    }

    public function scopeByCode($query, string $code): void
    {
        $query->where('code', '=', $code);
    }

    public function scopeByLocale($query, string $locale): void
    {
        $query->where('locale', '=', $locale);
    }

    /**
     * 按 code 和 locale 获取模板（自动 fallback 到默认语言）
     *
     * @param string $code   模板标识
     * @param string $locale 语言
     * @return static|null
     */
    public static function getByCode(string $code, string $locale = 'zh_CN'): ?self
    {
        $template = static::active()
            ->byCode($code)
            ->byLocale($locale)
            ->find();

        // fallback: 未找到指定语言时尝试默认语言
        if ($template === null && $locale !== 'zh_CN') {
            $template = static::active()
                ->byCode($code)
                ->byLocale('zh_CN')
                ->find();
        }

        return $template;
    }

    /**
     * 渲染模板
     *
     * @param array       $variables          变量键值对
     * @param string|null $locale             语言（null 则使用模板自身的 locale）
     * @param bool|null   $escapeHtmlOverride 是否 HTML 转义（null = 取 template.escape_html 配置）
     * @return Message
     */
    public function render(array $variables, ?string $locale = null, ?bool $escapeHtmlOverride = null): Message
    {
        $title   = $this->getAttr('title') ?? '';
        $content = $this->getAttr('content') ?? '';

        // 解析变量元信息
        $variableMeta = $this->parseVariableMeta();

        // 确定引擎模式
        $engineMode = config('wise-notification.template.engine_mode', 'extended');

        // HTML 转义只对 HTML 通道（email）有意义：短信 / 钉钉 / 企微等纯文本通道
        // 转义后会把 A&B 变成 A&amp;B、' 变成 &#039;，破坏正文与模板参数校验，
        // 故允许调用方按通道覆盖（默认沿用 template.escape_html 配置）
        $escapeHtml = $escapeHtmlOverride
            ?? (bool) config('wise-notification.template.escape_html', true);

        // 创建编译器并渲染
        $compiler = new TemplateCompiler(
            mode: $engineMode,
            escapeHtml: $escapeHtml,
            variableMeta: $variableMeta,
            allowObjectAccess: (bool) config('wise-notification.template.allow_object_access', false),
            allowRawOutput: (bool) config('wise-notification.template.allow_raw_output', false),
        );

        $title   = $compiler->render($title, $variables);
        $content = $compiler->render($content, $variables);

        return new Message(
            title: $title,
            content: $content,
            data: $variables,
        );
    }

    /**
     * 预览模板渲染结果
     *
     * 用于管理后台模板编辑时的实时预览。
     *
     * @param array       $variables          变量键值对
     * @param string|null $locale             语言
     * @param bool|null   $escapeHtmlOverride 是否 HTML 转义（null = 取 template.escape_html 配置）
     * @return array{title: string, content: string}
     */
    public function preview(array $variables, ?string $locale = null, ?bool $escapeHtmlOverride = null): array
    {
        $engineMode = config('wise-notification.template.engine_mode', 'extended');
        $variableMeta = $this->parseVariableMeta();
        $escapeHtml = $escapeHtmlOverride
            ?? (bool) config('wise-notification.template.escape_html', true);

        $compiler = new TemplateCompiler(
            mode: $engineMode,
            escapeHtml: $escapeHtml,
            variableMeta: $variableMeta,
            allowObjectAccess: (bool) config('wise-notification.template.allow_object_access', false),
            allowRawOutput: (bool) config('wise-notification.template.allow_raw_output', false),
        );

        return [
            'title'   => $compiler->render($this->getAttr('title') ?? '', $variables),
            'content' => $compiler->render($this->getAttr('content') ?? '', $variables),
        ];
    }

    /**
     * 分析模板中的变量引用（用于管理后台展示）
     *
     * @return array{simple: array, nested: array, conditions: array, loops: array}
     */
    public function analyzeVariables(): array
    {
        $engineMode = config('wise-notification.template.engine_mode', 'extended');
        $compiler = new TemplateCompiler(mode: $engineMode);

        $content = ($this->getAttr('title') ?? '') . "\n" . ($this->getAttr('content') ?? '');
        return $compiler->analyze($content);
    }

    /**
     * 解析模板变量元信息（内部共用）
     *
     * @return array|null 变量元信息数组，或 null
     */
    private function parseVariableMeta(): ?array
    {
        $raw = $this->getAttr('variables');
        if (empty($raw)) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }

        return is_array($raw) ? $raw : null;
    }

    /**
     * 获取模板支持的变量定义（解析 variables JSON）
     *
     * @return array 如 ['name' => ['required' => true, 'type' => 'string', 'description' => '用户名'], ...]
     */
    public function getVariableDefinitions(): array
    {
        $raw = $this->getAttr('variables');
        if (empty($raw)) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }
}
