<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported on line 1 of a Blade template the Blade compiler threw on, so the template is not
 * analyzed. The message names the compiler's exception class and message.
 */
final class BladeCompileFailure extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/BladeCompileFailure/';
}
