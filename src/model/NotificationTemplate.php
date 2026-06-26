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

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setName(config('wise-notification.table_names.templates', $this->name));
    }

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
     * @param array  $variables 变量键值对
     * @param string $locale    语言（null 则使用模板自身的 locale）
     * @return Message
     */
    public function render(array $variables, ?string $locale = null): Message
    {
        $title   = $this->getAttr('title') ?? '';
        $content = $this->getAttr('content') ?? '';

        // 解析变量元信息
        $variableMeta = null;
        $rawVariables = $this->getAttr('variables');
        if (!empty($rawVariables)) {
            if (is_string($rawVariables)) {
                $variableMeta = json_decode($rawVariables, true);
                if (!is_array($variableMeta)) {
                    $variableMeta = null;
                }
            } elseif (is_array($rawVariables)) {
                $variableMeta = $rawVariables;
            }
        }

        // 确定引擎模式
        $engineMode = config('wise-notification.template.engine_mode', 'extended');

        // 创建编译器并渲染
        $compiler = new TemplateCompiler(
            mode: $engineMode,
            escapeHtml: true,
            variableMeta: $variableMeta,
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
     * @param array  $variables 变量键值对
     * @param string $locale    语言
     * @return array{title: string, content: string}
     */
    public function preview(array $variables, ?string $locale = null): array
    {
        $engineMode = config('wise-notification.template.engine_mode', 'extended');

        $rawVariables = $this->getAttr('variables');
        $variableMeta = null;
        if (!empty($rawVariables)) {
            if (is_string($rawVariables)) {
                $variableMeta = json_decode($rawVariables, true);
            } elseif (is_array($rawVariables)) {
                $variableMeta = $rawVariables;
            }
        }

        $compiler = new TemplateCompiler(
            mode: $engineMode,
            escapeHtml: true,
            variableMeta: $variableMeta,
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
