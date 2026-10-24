<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when the value a call site passes for a Blade template variable does not satisfy the
 * type that template declares for it.
 */
final class InvalidViewData extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/InvalidViewData/';
}
