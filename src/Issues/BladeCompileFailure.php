<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported on line 1 of a Blade template the Blade compiler threw on, so the template is not
 * analyzed. The message carries the compiler's cause on one line, without the exception class.
 */
final class BladeCompileFailure extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/BladeCompileFailure/';

    // Explicit -1 like Psalm's ParseError: the template is not analyzed at all, so it is an error at every level.
    public const ERROR_LEVEL = -1;
}
