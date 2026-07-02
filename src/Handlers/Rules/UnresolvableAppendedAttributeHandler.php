<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\LaravelPlugin\Issues\UnresolvableAppendedAttribute;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;

/**
 * Flags an Eloquent `$appends` entry that no accessor or cast backs (#694). Such an entry is a runtime
 * fatal, not a silent `null`: `Model::attributesToArray()` runs `mutateAttributeForArray($key, null)`
 * over `$appends` with no existence guard, and when the key resolves to neither a class cast, a
 * new-style `Attribute` accessor, nor a legacy `getXxxAttribute()`, the call falls through to an
 * undefined `get<Studly>Attribute()`, forwards via `Model::__call()` to a query builder, and throws
 * `BadMethodCallException` on `toArray()` / `toJson()`. Detection counts any declared cast as backing
 * (see {@see backedKeys()} for why), so a flagged entry has neither an accessor nor a cast and is an
 * unconditional fatal. The rationale (and why plain columns / relations do NOT back an appended
 * attribute) lives on {@see UnresolvableAppendedAttribute}.
 *
 * Enabled by default: registered unconditionally (see Plugin::registerHandlers()), like
 * {@see PublicScopeAccessorVisibilityHandler}. Silence per project through the issueHandlers config.
 *
 * Hook choice — AfterCodebasePopulated:
 *  - It reads {@see ModelMetadataRegistry}, which {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 *    warms during its own AfterCodebasePopulated pass. This handler is registered AFTER it, and Psalm
 *    dispatches AfterCodebasePopulated handlers in registration order, so the registry is warm here.
 *  - The registry already flattens `$appends` and the full accessor set (self + traits + inherited
 *    ancestors) per model, so a trait- or parent-declared append is validated against the concrete
 *    model's complete accessor set without re-resolving inheritance here.
 *  - Emissions survive worker forking: the parent emits before `analyzeFiles()` forks, and
 *    `IssueBuffer::addIssues()` merges worker issues without resetting the parent buffer. The trade-off
 *    versus an analysis-time hook (shared with the sibling visibility handler): under a re-enabled
 *    `--diff`, a warm run that skips the model's file would not re-emit, because populate-time emissions
 *    are not replayed from the per-file issue cache. The pinned Psalm 7 beta hardcodes `is_diff = false`.
 *
 * Abstract bases are skipped: their `$appends` is validated through every concrete descendant, which
 * carries the complete accessor set (including accessors a child supplies for a base's appended
 * attribute — the template-method pattern). Checking the base directly would false-positive on that.
 */
final class UnresolvableAppendedAttributeHandler implements AfterCodebasePopulatedInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        foreach (ModelMetadataRegistry::all() as $fqcn => $metadata) {
            if ($metadata->appends === [] || !$provider->has($fqcn)) {
                continue;
            }

            $storage = $provider->get($fqcn);
            // Skip vendor models (mirrors the sibling visibility handler): the registry warms every
            // autoloadable Model subclass, so a package model with an unbacked $appends would otherwise
            // be reported in a file the user does not own. Abstract bases are validated through their
            // concrete descendants, which carry the appends plus the full accessor set (see the class
            // docblock); an abstract base can never be serialized on its own.
            if (!$storage->user_defined || $storage->abstract) {
                continue;
            }

            // Only entries Eloquent actually serializes can fatal — $hidden / $visible drop the rest
            // before the throwing loop.
            $appends = self::serializedAppends($metadata->appends, $metadata->hidden, $metadata->visible);
            $unresolved = self::unresolvedAppends($appends, self::backedKeys($metadata));
            if ($unresolved === []) {
                continue;
            }

            $location = self::appendsLocation($storage);
            if (!$location instanceof CodeLocation) {
                continue;
            }

            foreach ($unresolved as $append) {
                IssueBuffer::accepts(
                    new UnresolvableAppendedAttribute(self::message($fqcn, $append), $location),
                    $storage->suppressed_issues,
                );
            }
        }
    }

    /**
     * The `$appends` entries no accessor or cast backs, in declaration order. Pure verdict, split out so
     * it can be unit-tested without a warmed codebase (the registry only warms autoloadable model
     * classes, so this is not reachable from a phpt fixture).
     *
     * @param list<non-empty-string> $appends
     * @param array<non-empty-lowercase-string, true> $backedKeys Normalized identities Laravel can resolve.
     * @return list<non-empty-string>
     * @internal
     * @psalm-pure
     */
    public static function unresolvedAppends(array $appends, array $backedKeys): array
    {
        $unresolved = [];

        foreach ($appends as $append) {
            $key = EloquentModelMethods::accessorPropertyKey($append);
            // A name that collapses to empty (only separators) has no sensible accessor identity; leave
            // it to the user rather than guess.
            if ($key === null || isset($backedKeys[$key])) {
                continue;
            }

            $unresolved[] = $append;
        }

        return $unresolved;
    }

    /**
     * The `$appends` entries Eloquent still serializes after applying `$hidden` / `$visible`. An appended
     * attribute that is hidden, or (when `$visible` is non-empty) absent from `$visible`, is dropped by
     * `getArrayableItems()` BEFORE the loop that would fatal on it, so it never throws and must not be
     * flagged. Mirrors `HasAttributes::getArrayableItems()` (exact-name match, as Laravel does).
     *
     * @param list<non-empty-string> $appends
     * @param list<non-empty-string> $hidden
     * @param list<non-empty-string> $visible
     * @return list<non-empty-string>
     * @internal
     * @psalm-pure
     */
    public static function serializedAppends(array $appends, array $hidden, array $visible): array
    {
        // Flip to key sets, mirroring getArrayableItems()'s array_flip() + array_diff_key/intersect_key
        // (also O(1) lookups, and it sidesteps Psalm conflating two in_array() needle assertions).
        $hiddenSet = \array_fill_keys($hidden, true);
        $visibleSet = \array_fill_keys($visible, true);
        $result = [];

        foreach ($appends as $append) {
            if (isset($hiddenSet[$append])) {
                continue;
            }

            if ($visibleSet !== [] && !isset($visibleSet[$append])) {
                continue;
            }

            $result[] = $append;
        }

        return $result;
    }

    /**
     * Union of the normalized identities that resolve an appended attribute to a value at serialize time:
     * the model's accessors and every declared cast key.
     *
     * Any declared cast counts, not only class casts. The precise runtime rule is narrower (a class cast
     * resolves a value, a primitive/enum cast on a non-column key still throws), but the registry's
     * `CastShape` describes the inferred type, not the `isClassCastable()` branch, so it cannot tell the
     * two apart reliably (a first-party `Castable` such as `AsCollection::class` is shape `Primitive`).
     * Treating any cast as backing keeps the rule free of false positives: a flagged entry then has
     * neither an accessor nor a cast, which is an unconditional `BadMethodCallException`. The trade-off is
     * a rare false negative (a built-in-cast column listed in `$appends` without an accessor), which is
     * the safe direction for an always-on rule.
     *
     * @param ModelMetadata<Model> $metadata
     * @return array<non-empty-lowercase-string, true>
     * @psalm-mutation-free
     */
    private static function backedKeys(ModelMetadata $metadata): array
    {
        $backed = self::castKeys($metadata->casts());

        // accessors() is already keyed by the normalized accessor identity (accessorPropertyKey).
        foreach (\array_keys($metadata->accessors()) as $accessorKey) {
            $backed[$accessorKey] = true;
        }

        return $backed;
    }

    /**
     * Normalized identity set of every declared cast key. Public for unit testing (the registry path is
     * not reachable from a phpt fixture; see {@see unresolvedAppends()}).
     *
     * @param array<non-empty-string, CastInfo> $casts
     * @return array<non-empty-lowercase-string, true>
     * @internal
     * @psalm-pure
     */
    public static function castKeys(array $casts): array
    {
        $keys = [];

        foreach (\array_keys($casts) as $column) {
            $key = EloquentModelMethods::accessorPropertyKey($column);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * The model's own `$appends` declaration when it has one (the precise line), else the class
     * declaration. An inherited `$appends` lives on a parent/trait whose own pass reports it for that
     * declarer; here we anchor the concrete model's diagnostic to the model itself.
     *
     * @psalm-mutation-free
     */
    private static function appendsLocation(ClassLikeStorage $storage): ?CodeLocation
    {
        $appendsProperty = $storage->properties['appends'] ?? null;
        if ($appendsProperty !== null && $appendsProperty->location instanceof CodeLocation) {
            return $appendsProperty->location;
        }

        return $storage->location;
    }

    /**
     * Build the diagnostic, naming the conventional accessor spellings (`get<Studly>Attribute()` and the
     * new-style `<camel>(): Attribute`). Public for unit testing the studly transform.
     *
     * @param class-string $fqcn
     * @param non-empty-string $append
     * @internal
     * @psalm-pure
     */
    public static function message(string $fqcn, string $append): string
    {
        $studly = \str_replace(' ', '', \ucwords(\str_replace(['_', '-'], ' ', $append)));

        return \sprintf(
            "Appended attribute '%s' on %s has no accessor or cast to produce its value, so "
            . 'toArray()/toJson() throws BadMethodCallException at runtime. Define a get%sAttribute() '
            . "accessor (or a %s(): Attribute method), or remove '%s' from \$appends.",
            $append,
            $fqcn,
            $studly,
            \lcfirst($studly),
            $append,
        );
    }
}
