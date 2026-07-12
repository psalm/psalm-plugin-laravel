<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a relation name passed to an eager-loading / relationship-query
 * method (`with()`, `load()`, `has()`, `whereHas()`, ...) does not correspond to
 * a method on the resolved model. Passing a non-existent relation name is a
 * common source of runtime errors in Laravel applications.
 */
final class UndefinedModelRelation extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UndefinedModelRelation/';
}
