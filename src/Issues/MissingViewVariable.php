<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a Blade template declares a variable its call site never passes.
 */
final class MissingViewVariable extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingViewVariable/';

    // No ERROR_LEVEL override: controlled by the plugin setting blade validateViewData
}
