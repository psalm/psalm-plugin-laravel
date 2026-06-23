<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when an Eloquent model lists an attribute in `$appends` that no accessor or class cast
 * backs, so serializing the model is a runtime fatal — not a silent `null`.
 *
 * `Model::attributesToArray()` runs `mutateAttributeForArray($key, null)` for every `$appends` entry,
 * with no existence guard. That call resolves a value only through one of three paths: a class cast
 * (`isClassCastable()`), a new-style `Attribute`-returning accessor, or a legacy `getXxxAttribute()`.
 * When none exist it falls through to `$this->{'get'.Studly($key).'Attribute'}()`, which hits
 * `Model::__call()`, forwards to a fresh query builder, and throws `BadMethodCallException` the moment
 * the model is arrayed or JSON-encoded.
 *
 * Plain columns and relations do NOT back an appended attribute: the loop passes `null` as the value
 * and ignores the stored attribute, so appending a column name without a matching accessor throws all
 * the same. The rule therefore requires an accessor or a declared cast; an entry with neither is the
 * unconditional fatal above. Any declared cast is treated as backing (a conservative approximation of
 * `isClassCastable()`, since the registry cannot reliably tell a class cast from a primitive one), which
 * keeps this always-on rule free of false positives at the cost of a rare missed primitive-cast column.
 *
 * Enabled by default. Silence per project via
 * `<PluginIssue name="UnresolvableAppendedAttribute" errorLevel="suppress" />` in psalm.xml's
 * issueHandlers, or with an inline `@psalm-suppress UnresolvableAppendedAttribute` on the model.
 */
final class UnresolvableAppendedAttribute extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnresolvableAppendedAttribute/';

    // A genuine runtime fatal (BadMethodCallException on toArray()/toJson()), so report it across the
    // strict-to-moderate levels like the other real-bug rules (PublicModelScope, NoEnvOutsideConfig).
    public const ERROR_LEVEL = 4;
}
