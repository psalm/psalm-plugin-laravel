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
     * one is not invoked: the framework's `initializeHasAttributes` ({@see mirrorHasAttributesInitializer()}).
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
                self::mirrorHasAttributesInitializer($reflection, $instance);
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
     * is the only way to observe them at all. Because `casts()` is the one initializer input available
     * STATICALLY — {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser} AST-parses it and
     * {@see ModelMetadataRegistryBuilder::computeCasts()} merges that result last. Executing it would buy
     * little the registry lacks (the parser degrades what it cannot resolve to `'mixed'`), at the cost of
     * evaluating arbitrary user expressions inside warm-up.
     *
     * `casts()` is the only skipped PART, not the statement wrapping it. The initializer's three effects:
     *  - `ensureCastsAreStringValues()`, called below over the DECLARED `$casts` alone. It runs no `casts()`,
     *    so the rule above does not excuse skipping it: unnormalized, `protected $casts = ['x' =>
     *    [Foo::class, 'arg']]` stays an array where a constructed model holds `'Foo:arg'`, and computeCasts()
     *    drops that model's WHOLE casts section on the resulting TypeError (#1281).
     *  - `$dateFormat`, which nothing stores.
     *  - `mergeAppends()`, called below.
     *
     * The normalizer is CALLED, never copied: it is version-split inside the supported range — 12.14–12.25
     * has no `is_object` branch, 12.26+ adds one throwing `InvalidArgumentException` on a non-Stringable
     * object cast — so a copy would carry a gate that calling tracks for free. The `is_array` branch this
     * exists for is byte-identical across `illuminate/database: ^12.14 || ^13.3`, so it needs no gate.
     *
     * Built from `$instance`, never `Model::class`: `ReflectionMethod::invoke()` dispatches NON-virtually and
     * does not complain about a foreign receiver, so a `Model::class` handle would silently run the
     * framework's body on a model that overrides the normalizer. Not the public `mergeCasts()`, which
     * normalizes too: it merges from OUTSIDE over `$this->casts`, so the only raw-casts reader it could be
     * paired with — `getCasts()` — would bake the implicit key cast permanently into the property and defeat
     * computeCasts()'s setIncrementing() suppression.
     *
     * `#[Appends]` is Laravel 13.0+; below it classAttribute() matches nothing and that half no-ops — so
     * `mergeAppends()`, absent on 12.14–12.24, is never called and cannot crash warm-up.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function mirrorHasAttributesInitializer(\ReflectionClass $reflection, Model $instance): void
    {
        $declaredCasts = new \ReflectionProperty(Model::class, 'casts');
        $normalize = new \ReflectionMethod($instance, 'ensureCastsAreStringValues');

        try {
            // Read-normalize-write as ONE statement: getValue() and invoke() both return mixed, and binding
            // either to a local would count against the plugin's 100% type-coverage target.
            $declaredCasts->setValue($instance, $normalize->invoke($instance, $declaredCasts->getValue($instance)));
        } catch (\InvalidArgumentException) {
            // 12.26+ raises this for a non-Stringable object cast. Runtime normalizes the MERGED map and
            // `casts()` wins on collisions, so a declared object cast that `casts()` overrides never reaches
            // the throw at construction. Seeing the declared half alone, this cannot tell that model from an
            // unconstructable one — so leave `$casts` raw and defer to computeCasts(), which holds the
            // AST-parsed `casts()`, merges it last, and widens whatever survives. Nothing is written when
            // invoke() throws, so the raw value stands.
        }

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
