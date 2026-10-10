<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * How an override value is validated and shown in `analyze --help`.
 *
 * @internal
 */
enum SettingType: string
{
    case Bool = 'true|false';
    case Enum = 'enum';
    case Path = 'path';
    case PathList = 'path, repeatable';
}
