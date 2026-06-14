<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent model exposes a `public` query scope: a legacy `scopeXxx()` method or a
 * `#[Scope]`-attributed method.
 *
 * Laravel dispatches scopes indirectly through the query builder (`Post::query()->published()`), never by
 * their declared name, so `public` only widens the model's API surface. A `public` `#[Scope]` is worse
 * than a smell: calling it statically (`Post::published()`) is a runtime fatal, because PHP resolves the
 * accessible non-static method and throws before `__callStatic` can route it through the builder (see
 * #634 / vimeo/psalm#11876). Laravel's convention is `protected`.
 *
 * Only `public` is reported; `private` is left alone (a private `#[Scope]` is rejected by Laravel and
 * surfaces elsewhere, and a private legacy scope is a separate dead-code question). Larastan's
 * NoPublicModelScopeAndAccessorRule additionally flags `private`.
 *
 * Enabled by default. Silence per project via
 * `<PluginIssue name="PublicModelScope" errorLevel="suppress" />` in psalm.xml's issueHandlers.
 */
final class PublicModelScope extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/PublicModelScope/';

    // No ERROR_LEVEL override: a public scope is a definite convention violation, reported as an error at
    // every project level (inherits CodeIssue::ERROR_LEVEL = -1), like ModelMakeDiscouraged. Downgrade or
    // silence per project through the issueHandlers config if desired.
}
