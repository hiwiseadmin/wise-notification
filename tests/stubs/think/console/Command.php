<?php

declare(strict_types=1);

namespace think\console;

use think\console\input\Option;

/**
 * ThinkPHP Console Command 基类桩
 */
abstract class Command
{
    /** @var object App 容器 */
    protected $app;

    /** @var string 命令名称 */
    protected string $name = '';

    /** @var string 命令描述 */
    protected string $description = '';

    /**
     * 设置命令名称
     */
    protected function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 设置命令描述
     */
    protected function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * 添加参数
     */
    protected function addArgument(string $name, int $mode = 1, string $description = '', mixed $default = null): static
    {
        // no-op for testing
        return $this;
    }

    /**
     * 添加选项
     */
    protected function addOption(string $name, ?string $shortcut = null, int $mode = 1, string $description = '', mixed $default = null): static
    {
        // no-op for testing
        return $this;
    }

    /**
     * 设置帮助信息
     */
    protected function setHelp(string $help): static
    {
        // no-op for testing
        return $this;
    }

    /**
     * 配置命令
     */
    abstract protected function configure(): void;

    /**
     * 执行命令
     */
    abstract protected function execute(Input $input, Output $output);
}

/**
 * ThinkPHP Console Input 桩
 */
class Input
{
    /**
     * 获取选项值
     */
    public function getOption(string $name): mixed
    {
        return null;
    }
}

/**
 * ThinkPHP Console Output 桩
 */
class Output
{
    /** @var array<int, string> 输出消息 */
    private array $messages = [];

    /**
     * 输出信息
     */
    public function writeln(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * 输出 info
     */
    public function info(string $message): void
    {
        $this->messages[] = '[info] ' . $message;
    }

    /**
     * 输出 warning
     */
    public function warning(string $message): void
    {
        $this->messages[] = '[warning] ' . $message;
    }

    /**
     * 输出 error
     */
    public function error(string $message): void
    {
        $this->messages[] = '[error] ' . $message;
    }

    /**
     * 获取所有输出消息
     *
     * @return array<int, string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
