<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent model exposes a `public` legacy convention method that Laravel dispatches
 * indirectly and the convention wants `protected`:
 *
 *  - a legacy `scopeXxx()` query scope (dispatched through the query builder), or
 *  - a legacy attribute accessor / mutator `getXxxAttribute()` / `setXxxAttribute()` (dispatched through
 *    `__get()` / `__set()` magic).
 *
 * These are never called by their declared name, so `public` only widens the model's API surface. Unlike a
 * `public` `#[Scope]`, whose idiomatic static call fatals (see {@see PublicModelScope}), these break nothing
 * on the path anyone writes, so each is a pure convention nit on otherwise-correct code. Only `public` is
 * reported; `private` is a separate dead-code question. The modern `firstName(): Attribute` accessor form is
 * out of scope.
 *
 * Enabled by default. Silence per project via
 * `<PluginIssue name="PublicModelAccessor" errorLevel="suppress" />` in psalm.xml's issueHandlers.
 */
final class PublicModelAccessor extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/PublicModelAccessor/';

    // A public legacy scope/accessor works fine at runtime; it is purely a visibility convention. Report it
    // as an error only at Psalm's strictest level (1) and downgrade for everyone else, i.e. you opt into a
    // hard failure only by having already chosen maximum strictness.
    public const ERROR_LEVEL = 1;
}
