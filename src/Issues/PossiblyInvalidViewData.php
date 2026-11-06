<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when the value a call site passes for a Blade template variable only partly satisfies the
 * type that template declares for it: possibly null, a parent type of the declared one, or a union
 * with a member that does not fit. The lower-severity sibling of {@see InvalidViewData}.
 */
final class PossiblyInvalidViewData extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/PossiblyInvalidViewData/';

    // Level 3 like Psalm's PossiblyInvalidArgument, ArgumentTypeCoercion and PossiblyNullArgument.
    public const ERROR_LEVEL = 3;
}
