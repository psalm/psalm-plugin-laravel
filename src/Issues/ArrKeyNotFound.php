<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when `Arr::get()` or `Arr::pull()` looks up a literal key that cannot exist
 * in the sealed array shape it is given.
 */
final class ArrKeyNotFound extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/ArrKeyNotFound/';

    // No ERROR_LEVEL override: controlled by the plugin setting findArrKeyNotFound
}
