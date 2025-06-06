<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use function getenv;
use function rtrim;

use const DIRECTORY_SEPARATOR;

final class CacheDirectoryProvider
{
    public static function getCacheLocation(): string
    {
        $env = getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        if ($env !== false && $env !== '') {
            return rtrim($env, DIRECTORY_SEPARATOR);
        }

        return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'cache';
    }
}
