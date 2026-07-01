<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Foundation\Application;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;

/**
 * Classification and traversal helpers for the methods Eloquent dispatches indirectly: query scopes
 * (legacy `scopeXxx()` and `#[Scope]`-attributed) and legacy attribute accessors/mutators
 * (`getXxxAttribute()` / `setXxxAttribute()`).
 *
 * These four primitives were originally private to {@see \Psalm\LaravelPlugin\Handlers\Diagnostics\SuppressHandler}
 * (#874, #1048). They live here because three independent handlers now need the same classification:
 *  - SuppressHandler silences PossiblyUnusedMethod/UnusedMethod on them (they have no visible caller);
 *  - {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler} resolves #[Scope] call types;
 *  - {@see \Psalm\LaravelPlugin\Handlers\Rules\PublicScopeAccessorVisibilityHandler} enforces the
 *    protected-visibility convention (#695).
 *
 * Keeping a single copy stops the StudlyCase / declaring-storage subtleties from drifting apart.
 *
 * @internal
 * @psalm-immutable
 */
final class EloquentModelMethods
{
    /**
     * Yield, for every method that *appears* on $classStorage, the MethodStorage of the class that
     * actually declares it — the class itself, a trait it composes, or a parent.
     *
     * Eloquent hosts scopes and accessors on traits at least as often as on the class body, and Psalm
     * never copies a trait method's MethodStorage into the using class's `$storage->methods`
     * (Populator::inheritMethodsFromParent only wires up appearing_method_ids / declaring_method_ids,
     * never `methods`). So iterating `$classStorage->methods` misses trait-hosted scopes entirely.
     * Resolving to the declaring storage mirrors SuppressHandler::markFrameworkInitializedProperties()
     * (declaring_property_ids) and BuilderScopeHandler::hasScopeAttribute() (#1046).
     *
     * Only methods whose appearing class is $classStorage itself are visited: a method inherited from
     * a parent appears on (and is yielded for) the parent when that parent is iterated separately, so a
     * caller looping the whole codebase sees each declaration once per declaring storage. A trait method
     * shared by N composing classes yields the SAME MethodStorage instance N times (once per composer);
     * a caller that emits must dedupe on the storage identity (the suppressors here mutate idempotently
     * so they do not).
     *
     * Mutation-free: only reads the storage graph and yields existing MethodStorage instances.
     *
     * @return \Generator<lowercase-string, MethodStorage>
     *
     * @psalm-mutation-free
     */
    public static function appearingMethods(
        ClassLikeStorage $classStorage,
        ClassLikeStorageProvider $provider,
    ): \Generator {
        foreach ($classStorage->appearing_method_ids as $methodName => $appearingMethodId) {
            if ($appearingMethodId->fq_class_name !== $classStorage->name) {
                continue;
            }

            $declaringMethodId = $classStorage->declaring_method_ids[$methodName] ?? $appearingMethodId;

            if (!$provider->has($declaringMethodId->fq_class_name)) {
                continue;
            }

            $declaringMethodStorage
                = $provider->get($declaringMethodId->fq_class_name)->methods[$declaringMethodId->method_name] ?? null;

            if ($declaringMethodStorage instanceof MethodStorage) {
                yield $methodName => $declaringMethodStorage;
            }
        }
    }

    /**
     * Whether $methodStorage carries the modern `#[Scope]` attribute.
     *
     * Psalm stores attribute metadata on the *declaring* class's MethodStorage, so callers should pass
     * the declaring storage (via {@see self::appearingMethods()} or `getDeclaringMethodId()`), not the
     * inheriting class's entry.
     *
     * A private `#[Scope]` is never a usable scope on any supported Laravel (12-13): 13.8+ rejects it in
     * Model::isScopeMethodWithAttribute (`! isPrivate() && ...`), and on 12.4-13.7 callNamedScope
     * dispatches from the base Model's `$this`, where a subclass's private method is unreachable, so it
     * routes back through __call and recurses. Either way it cannot dispatch, so report it as "not a
     * scope" rather than treating it as a real (dead) one.
     *
     * @psalm-mutation-free
     */
    public static function hasScopeAttribute(MethodStorage $methodStorage): bool
    {
        // The #[Scope] attribute ships with Laravel 12. On Laravel 11 the attribute class
        // does not exist, so a method written with #[Scope] is not a usable scope there —
        // Psalm already reports UndefinedAttributeClass on the declaration. The check below
        // matches by attribute name only and cannot tell a defined attribute from an
        // undefined one, so without this version gate the scope handlers (unused-method
        // suppression, call-site resolution, PublicModelScope) would treat a non-functional
        // Laravel 11 attribute as a real scope. Application::VERSION reflects the booted app
        // the plugin analyses (same gate Plugin/StubFileFinder use for version-specific stubs).
        if (\version_compare(Application::VERSION, '12.0.0', '<')) {
            return false;
        }

        if ($methodStorage->visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
            return false;
        }

        foreach ($methodStorage->attributes as $attribute) {
            if ($attribute->fq_class_name === Scope::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a method is a legacy `scopeXxx()` query scope.
     *
     * Detection: the lowercase key starts with `scope` AND the original-cased name has an uppercase
     * ASCII letter directly after `scope` — the `scope` + StudlyCase convention Laravel resolves via
     * `'scope' . ucfirst($scope)` in Model::callNamedScope(). PHP dispatch is case-insensitive, so a
     * `scopeactive()` is technically reachable as `active()`; we still require the uppercase letter to
     * avoid matching ordinary methods that merely share the prefix (`scoped()`, `scopes()`,
     * `scopedQuery()`). A literal `scope()` is excluded by the length guard.
     *
     * @psalm-pure
     */
    public static function isLegacyScopeMethodName(string $lowercaseName, ?string $casedName): bool
    {
        if ($casedName === null || \strlen($casedName) < 6) {
            return false;
        }

        if (!\str_starts_with($lowercaseName, 'scope')) {
            return false;
        }

        $firstNameChar = $casedName[5];

        return $firstNameChar >= 'A' && $firstNameChar <= 'Z';
    }

    /**
     * Whether a method is a legacy attribute accessor (`getXxxAttribute()`) or mutator
     * (`setXxxAttribute()`), dispatched via Eloquent's `__get()` / `__set()` magic.
     *
     * Matches the lowercase method key — `$classStorage->methods` and appearing_method_ids are keyed
     * lowercase. The `.+` requires at least one character between the prefix and `attribute`, so the
     * framework's own bare `getAttribute()` / `setAttribute()` are not matched.
     *
     * @psalm-pure
     */
    public static function isLegacyAccessorMethodName(string $lowercaseName): bool
    {
        return \preg_match('/^get.+attribute$/', $lowercaseName) === 1
            || \preg_match('/^set.+attribute$/', $lowercaseName) === 1;
    }

    /**
     * Whether a method is a trait boot/initialize hook Laravel dispatches by reflection in
     * Model::bootTraits()/initializeTraits(), which findUnusedCode wrongly flags (#1069).
     *
     * Matches the cased name against `boot`/`initialize` + the declaring trait's basename.
     * Case-sensitive on purpose: bootTraits() collects via `in_array($method->getName(), [...])`, so a
     * mis-cased hook is never booted. The `boot` prefix is static-only; `initialize` is not. Caller must
     * confirm $definingTraitFqcn is a trait.
     *
     * @psalm-pure
     */
    public static function isTraitBootHook(?string $casedName, string $definingTraitFqcn, bool $isStatic): bool
    {
        if ($casedName === null) {
            return false;
        }

        $separatorPosition = \strrpos($definingTraitFqcn, '\\');
        $traitBasename = $separatorPosition === false
            ? $definingTraitFqcn
            : \substr($definingTraitFqcn, $separatorPosition + 1);

        if ($casedName === 'boot' . $traitBasename) {
            return $isStatic;
        }

        return $casedName === 'initialize' . $traitBasename;
    }
}
