<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a `view()` call site passes a data key that neither the rendered template nor any
 * template it hands its whole scope to (`@include`, `@extends`) ever reads or declares.
 */
final class UnusedViewData extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnusedViewData/';

    // No ERROR_LEVEL override: controlled by the plugin setting reportUnusedViewData
}
