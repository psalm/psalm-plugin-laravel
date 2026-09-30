<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a Blade template has no statically-provable reference anywhere in the project:
 * no `view()`/`View::make()` call site, and no `@include`/`@extends` from another template.
 */
final class UnusedView extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnusedView/';

    // No ERROR_LEVEL override: controlled by the plugin setting reportUnusedViews
}
