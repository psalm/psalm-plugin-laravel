<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent model exposes a `public` `#[Scope]`-attributed query scope.
 *
 * A `public` `#[Scope]` is more than a convention slip: calling it statically (`Post::published()`) is a
 * runtime fatal, because PHP resolves the accessible non-static method and throws before `__callStatic`
 * can route it through the query builder (see #634 / vimeo/psalm#11876). Laravel's convention is
 * `protected`, which keeps the static call routed to the builder.
 *
 * Legacy `scopeXxx()` scopes are NOT reported here. Their idiomatic call (`Post::active()`) is unaffected
 * by visibility, so the plugin treats them as a pure convention nit under {@see PublicModelAccessor}. Only
 * `public` is reported; `private` is a separate dead-code question (a private `#[Scope]` is also rejected
 * by Laravel).
 *
 * Enabled by default. Silence per project via
 * `<PluginIssue name="PublicModelScope" errorLevel="suppress" />` in psalm.xml's issueHandlers.
 */
final class PublicModelScope extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/PublicModelScope/';

    // A public #[Scope] enables a real runtime fatal (static dispatch), so report it as an error for
    // strict-to-moderate projects (levels 1-4) and downgrade only for loose projects (5-8). Not -1: the
    // declaration itself dispatches fine as a scope; it only becomes a fault if someone calls it
    // statically. Mirrors NoEnvOutsideConfig's level for "real-consequence but contextual".
    public const ERROR_LEVEL = 4;
}
