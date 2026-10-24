<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a Blade template declares a variable its call site never passes.
 */
final class MissingViewData extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingViewData/';

    // Level 6 like Psalm's UndefinedPropertyFetch: error at `init`'s level 4, demotable at 7-8 for apps that rely on runtime `share()` data.
    public const ERROR_LEVEL = 6;
}
