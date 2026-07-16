<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Model;

/**
 * Replays {@see Model}::__construct()'s initializer lifecycle on a constructor-less instance.
 *
 * {@see ModelMetadataRegistryBuilder} reads a model's runtime fields off a `newInstanceWithoutConstructor()`
 * throwaway, which skips `initializeTraits()` / `initializeModelAttributes()`; unreplayed, everything those
 * merge stays invisible to it.
 *
 * Laravel and reflection only — no Psalm, no sections. Throws propagate: the caller owns failure policy.
 *
 * @internal
 */
final class ModelInstancePreparer
{
    /**
     * Unreplayed, a cast a user trait merges is invisible to
     * {@see ModelMetadataRegistryBuilder::computeCasts()}, so an `$appends` entry it backs looks unbacked and
     * false-positives {@see \Psalm\LaravelPlugin\Handlers\Rules\UnresolvableAppendedModelAttributeHandler}.
     *
     * ONE walk over `getMethods()`: that order IS Laravel's initializer order (12.22+) and is PHP-dependent
     * (8.4 ranks Model's inherited concern inits first, 8.5 the class's own trait inits), so executing in it
     * matches runtime under either. Discovery mirrors bootTraits(): conventional name or `#[Initialize]`
     * (gated — the framework ignores the tag below 12.22), neither filtering isStatic.
     *
     * Framework and user initializers are INVOKED alike, so each does whatever the INSTALLED Laravel does —
     * version-correct by construction, with no hand-written mirror to drift when a release moves one. Exactly
     * one is not invoked: the framework's `initializeHasAttributes` ({@see applyAppendsAttribute()}).
     *
     * Not `initializeTraits()`: it reads `static::$traitInitializers`, populated only by `bootTraits()` — and
     * booting registers global scopes, a Psalm hang. `boot{Trait}()` is skipped for the same reason, so an
     * initializer depending on boot-prepared state may fail here even though runtime construction succeeds.
     * That trade is deliberate: the caller withholds the affected sections rather than risk wrong diagnostics.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    public static function prepare(\ReflectionClass $reflection, Model $instance): void
    {
        // filterStringList() also fixes the value type: self-analysis does not load the plugin's own stubs, so
        // class_uses_recursive() is typed `array` and its values would be mixed.
        $conventional = [];
        foreach (self::filterStringList(\class_uses_recursive($instance)) as $trait) {
            $conventional['initialize' . \class_basename($trait)] = true;
        }

        $honorInitializeAttribute = \class_exists(Initialize::class);
        // Root from Model's OWN file, never a hardcoded `/vendor/…` substring — survives custom vendor dirs,
        // path-repo symlinks and split illuminate/* packages. Trailing '/' stops `Illuminate\DatabaseExtra`.
        $eloquentRoot = \str_replace('\\', '/', \dirname((string) (new \ReflectionClass(Model::class))->getFileName(), 2));

        $invoked = [];
        foreach ($reflection->getMethods() as $method) {
            $name = $method->getName();
            // isset() first: getAttributes() is reached only for non-conventional methods.
            $isInitializer = isset($conventional[$name])
                || ($honorInitializeAttribute && $method->getAttributes(Initialize::class) !== []);
            if (isset($invoked[$name]) || !$isInitializer) {
                continue;
            }

            $invoked[$name] = true;
            // Discriminated by SOURCE FILE, not declaring class: a directly-`use`d concern flattens to report
            // the user model as declarer. So anything declared in the user's own file — their own trait named
            // `HasAttributes`, or an override on the model — takes the invoke branch, exactly as it would
            // dispatch at runtime. An override that calls `parent::initializeHasAttributes()` therefore does
            // reach `casts()`; that is the honest limit of the rule below, and it is not fixable by
            // discrimination (no reflection can tell an override that calls up from one that does not).
            $isFrameworkHasAttributes = $name === 'initializeHasAttributes'
                && \str_starts_with(\str_replace('\\', '/', (string) $method->getFileName()), $eloquentRoot . '/');

            if ($isFrameworkHasAttributes) {
                self::applyAppendsAttribute($reflection, $instance);
            } else {
                // Reflection, NOT $instance->{$name}(): a protected initializer called from outside routes to
                // Model::__call() query-builder forwarding instead of running. Bare statement keeps the mixed
                // return off the coverage tally.
                $method->invoke($instance);
            }
        }

        // The initializeModelAttributes phase — `#[Table]`/`#[Connection]` plus the primary-key sub-overrides
        // they carry. Runs after initializeTraits at runtime, so last here too. The method is Laravel 13.0;
        // the 12.x floor has no such phase at all, which is what the guard is for (12.x reaching the call
        // would route it through Model::__call() to the query builder). Not every attribute it reads is 13.0
        // — `#[WithoutIncrementing]` is 13.2 — but `^13.3` puts the whole set below the floor.
        if (\method_exists($instance, 'initializeModelAttributes')) {
            $instance->initializeModelAttributes();
        }
    }

    /**
     * The framework's `initializeHasAttributes` is the one initializer stood in for rather than invoked.
     *
     * NOT because it runs user code: the walk above invokes user trait initializers deliberately, and doing so
     * is the only way to observe them at all. Because `casts()` is the one initializer input already available
     * STATICALLY — {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser} AST-parses it and
     * {@see ModelMetadataRegistryBuilder::computeCasts()} merges that result last. Executing it would buy no
     * information the registry does not already have, at the cost of evaluating arbitrary user expressions
     * inside warm-up. A user trait initializer has no such static equivalent, which is what makes the two
     * different — not who wrote them.
     *
     * Standing in for it costs three effects, of which only the third is reproduced:
     *  - `ensureCastsAreStringValues()`, which also normalizes the DECLARED `$casts`. No `casts()` call is
     *    involved, so the rule above does NOT excuse skipping it: a `protected $casts = ['x' => [Foo::class,
     *    'arg']]` stays an array here where a constructed model holds `'Foo:arg'`, and computeCasts() then
     *    drops that model's whole casts section on the resulting TypeError. Pre-existing, not this rework's
     *    doing, tracked as #1281 — do not read this mirror as evidence the gap is intended.
     *  - `$dateFormat`, which nothing stores.
     *  - `mergeAppends()`, mirrored below.
     *
     * `#[Appends]` is Laravel 13.0+; below it classAttribute() matches nothing and this no-ops — so
     * `mergeAppends()`, absent on 12.14–12.24, is never called and cannot crash warm-up. Identical across the
     * whole `illuminate/database: ^12.14 || ^13.3` range.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyAppendsAttribute(\ReflectionClass $reflection, Model $instance): void
    {
        $appends = self::classAttribute($reflection, Appends::class);
        if ($appends !== null) {
            $instance->mergeAppends($appends->columns);
        }
    }

    /**
     * Mirrors {@see Model}::resolveClassAttribute(): first ancestor's first instance, no cross-ancestor merge.
     *
     * Name-matches an attribute whose class may be absent, and throws on `newInstance()` if so. The
     * `illuminate/database: ^12.14 || ^13.3` floor is what keeps that unreachable — only a model naming a
     * class its own framework lacks reaches it, code Laravel fatals on too, since `Error` escapes
     * resolveClassAttribute()'s `catch (Exception)`. Re-check this if the floor ever widens.
     *
     * @template T of object
     * @param \ReflectionClass<object> $reflection
     * @param class-string<T>          $attributeClass
     * @return T|null
     */
    private static function classAttribute(\ReflectionClass $reflection, string $attributeClass): ?object
    {
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes($attributeClass);
            if ($attributes === []) {
                continue;
            }

            return $attributes[0]->newInstance();
        }

        return null;
    }

    /**
     * Deliberate copy of {@see ModelMetadataRegistryBuilder}'s: its other sections still need theirs, and
     * calling across would point this class back at the one that depends on it.
     *
     * @param array<array-key, mixed> $values
     * @return list<non-empty-string>
     * @psalm-pure
     */
    private static function filterStringList(array $values): array
    {
        /** @var list<non-empty-string> */
        return \array_values(\array_filter(
            $values,
            static fn(mixed $entry): bool => \is_string($entry) && $entry !== '',
        ));
    }
}
