<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a query builder or local scope method is invoked directly on an Eloquent
 * model — statically (`User::where(...)`, `User::active()`) or on an instance
 * (`$user->where(...)`) — instead of through an explicit `query()` entry point.
 *
 * Opt-in only: emitted exclusively when `<reportImplicitQueryBuilderCalls value="true" />` is set
 * on the `<pluginClass>` element in psalm.xml. Teams enable it to forbid Laravel's
 * `__callStatic` / `__call` magic forwarding and require the explicit `Model::query()->...`
 * form, which keeps the call chain concrete and easy to follow for both readers and tooling.
 */
final class ImplicitQueryBuilderCall extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/ImplicitQueryBuilderCall/';
}
