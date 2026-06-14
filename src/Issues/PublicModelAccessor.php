<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent model exposes a `public` legacy attribute accessor or mutator:
 * `getXxxAttribute()` / `setXxxAttribute()`.
 *
 * Laravel dispatches these through `__get()` / `__set()` magic (`$post->title`), never by their declared
 * name, so `public` only widens the model's API surface. Laravel's convention is `protected`.
 *
 * Scope: only the legacy `getXxxAttribute()` / `setXxxAttribute()` form is detected; the modern
 * `firstName(): Attribute` form is out of scope. Only `public` is reported (a private legacy accessor is
 * a separate dead-code question). Larastan's NoPublicModelScopeAndAccessorRule instead targets the modern
 * Attribute form and also flags `private`.
 *
 * Enabled by default. Silence per project via
 * `<PluginIssue name="PublicModelAccessor" errorLevel="suppress" />` in psalm.xml's issueHandlers.
 */
final class PublicModelAccessor extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/PublicModelAccessor/';

    // No ERROR_LEVEL override: a public accessor/mutator is a definite convention violation, reported as an
    // error at every project level (inherits CodeIssue::ERROR_LEVEL = -1), like ModelMakeDiscouraged.
    // Downgrade or silence per project through the issueHandlers config if desired.
}
