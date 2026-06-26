<?php

declare(strict_types=1);

namespace think\console\input;

/**
 * ThinkPHP Console Input Option 桩
 */
class Option
{
    const VALUE_REQUIRED = 2;
    const VALUE_OPTIONAL = 4;
    const VALUE_NONE     = 1;
    const VALUE_IS_ARRAY = 8;
}
