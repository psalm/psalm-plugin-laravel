<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use function getenv;
use function rtrim;

final class CacheDirectoryProvider
{
    public static function getCacheLocation(): string
    {
        $env = getenv('LARAVEL_PLUGIN_CACHE_PATH');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }

        return __DIR__ . '/../../cache';
    }
}
