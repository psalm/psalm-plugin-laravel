<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when the value a call site passes for a Blade template variable does not satisfy the
 * type that template declares for it.
 */
final class InvalidViewVariableType extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/InvalidViewVariableType/';

    // No ERROR_LEVEL override: controlled by the plugin setting blade validateViewData
}
