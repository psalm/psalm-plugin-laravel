<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors {@see Model}::__construct()'s initializer lifecycle on a constructor-less instance.
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
     * Framework concerns run a MIRROR at their position rather than being invoked, discriminated by SOURCE
     * FILE, not declaring class: a directly-`use`d concern flattens to report the user model as declarer.
     * `#[Connection]`/`#[Table]` run last, as at runtime.
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
            if (\str_starts_with(\str_replace('\\', '/', (string) $method->getFileName()), $eloquentRoot . '/')) {
                self::applyConcernMirror($name, $reflection, $instance);
            } else {
                // Reflection, NOT $instance->{$name}(): a protected initializer called from outside routes to
                // Model::__call() query-builder forwarding instead of running. Bare statement keeps the mixed
                // return off the coverage tally.
                $method->invoke($instance);
            }
        }

        // initializeModelAttributes phase — always after initializeTraits at runtime.
        self::applyConnectionAttribute($reflection, $instance);
        self::applyTableAttribute($reflection, $instance);
    }

    /**
     * Hand-written mirrors of the framework concerns: HasAttributes → `mergeAppends(#[Appends])` (its
     * `casts()` merge is {@see ModelMetadataRegistryBuilder::computeCasts()}'s job, `dateFormat` isn't stored);
     * HidesAttributes → `mergeHidden`/`mergeVisible`; GuardsAttributes → `mergeFillable(#[Fillable])` +
     * `#[Guarded]`/`#[Unguarded]`; HasUniqueStringIds → `usesUniqueIds = true`; HasTimestamps →
     * `#[WithoutTimestamps]`/`#[Table(timestamps:)]`. HasRelationships / SoftDeletes no-op — nothing they
     * set is stored (SoftDeletes' `deleted_at` cast comes from computeCasts()).
     *
     * Audited against every `initialize*` under vendor `Illuminate\Database\` — the walk's skip root, and
     * wider than `Eloquent\Concerns`, since SoftDeletes and Model itself sit outside it.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyConcernMirror(string $name, \ReflectionClass $reflection, Model $instance): void
    {
        if ($name === 'initializeHasUniqueStringIds') {
            self::flipUsesUniqueIds($instance);
        } elseif ($name === 'initializeHasAttributes') {
            self::applyAppendsAttribute($reflection, $instance);
        } elseif ($name === 'initializeHidesAttributes') {
            self::applyHiddenVisibleAttributes($reflection, $instance);
        } elseif ($name === 'initializeGuardsAttributes') {
            self::applyFillableGuardedAttributes($reflection, $instance);
        } elseif ($name === 'initializeHasTimestamps') {
            self::applyTimestampsAttributes($reflection, $instance);
        }
    }

    /**
     * What HasUniqueStringIds' initializer does: makes `getKeyType()`/`getIncrementing()` return the
     * string/non-incrementing values Laravel returns at runtime.
     */
    private static function flipUsesUniqueIds(Model $instance): void
    {
        $property = new \ReflectionProperty($instance, 'usesUniqueIds');
        $property->setValue($instance, true);
    }

    /**
     * `#[Appends]` is Laravel 13.0+; below it classAttribute() matches nothing and this no-ops — so
     * `mergeAppends()`, absent on 12.14–12.24, is never called and cannot crash warm-up.
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
     * Both attributes and helpers are Laravel-13.0; an absent attribute no-ops
     * (see {@see self::applyAppendsAttribute()}).
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyHiddenVisibleAttributes(\ReflectionClass $reflection, Model $instance): void
    {
        $hidden = self::classAttribute($reflection, Hidden::class);
        if ($hidden !== null) {
            $instance->mergeHidden($hidden->columns);
        }

        $visible = self::classAttribute($reflection, Visible::class);
        if ($visible !== null) {
            $instance->mergeVisible($visible->columns);
        }
    }

    /**
     * Known gap, applied by no mirror: `#[Table]`'s `key`/`keyType`/`incrementing` sub-overrides and
     * `#[WithoutIncrementing]`, which `initializeModelAttributes()` feeds into the primary key.
     * {@see ModelMetadataRegistryBuilder::computePrimaryKey()} reads only the raw getter defaults; the table
     * NAME (the serialization-relevant part) IS applied by {@see self::applyTableAttribute()}.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyFillableGuardedAttributes(\ReflectionClass $reflection, Model $instance): void
    {
        $fillable = self::classAttribute($reflection, Fillable::class);
        if ($fillable !== null) {
            $instance->mergeFillable($fillable->columns);
        }

        self::applyGuardedAttribute($reflection, $instance);
    }

    /**
     * `#[Guarded]`/`#[Unguarded]` only replace the default `['*']`, mirroring
     * {@see \Illuminate\Database\Eloquent\Concerns\GuardsAttributes::initializeGuardsAttributes()}.
     *
     * The early-return matches runtime precisely because this mirror runs at initializeGuardsAttributes'
     * position in the walk: a user initializer that ran earlier (per that PHP's order) and called guard()
     * makes runtime skip `#[Guarded]` too.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyGuardedAttribute(\ReflectionClass $reflection, Model $instance): void
    {
        if ($instance->getGuarded() !== ['*']) {
            return;
        }

        if (self::classAttribute($reflection, Unguarded::class) !== null) {
            $instance->guard([]);

            return;
        }

        // Presence, not non-emptiness, gates the replace: `#[Guarded()]` with no columns sets [], matching
        // Laravel's `columns ?? ['*']`.
        $guarded = self::classAttribute($reflection, Guarded::class);
        if ($guarded !== null) {
            $instance->guard($guarded->columns);
        }
    }

    /**
     * Mirror of `initializeHasTimestamps`, precedence included. Feeds `TraitFlags::$usesTimestamps` through
     * {@see ModelMetadataRegistryBuilder::readRuntimeConfiguration()}'s `usesTimestamps()` read. #1276.
     *
     * The `!== true` early-return is Laravel's own `=== true` guard: an attribute only speaks while
     * `$timestamps` is still `true`. Both ways of closing it are live — a declared `$timestamps = false` is a
     * property default `newInstanceWithoutConstructor()` applies, and a user initializer that ran earlier in
     * the walk has already written it (same position-dependence as {@see applyGuardedAttribute()}).
     *
     * No `class_exists` gate. `initializeHasTimestamps()` is 13.0+, so the 12.x floor never reaches this.
     * Above it, `#[WithoutTimestamps]` (13.2) is newer than the initializer that dispatches here, and package
     * atomicity does NOT close that window — 13.1 ships the initializer without the attribute in one artifact.
     * Composer's `require` on `illuminate/database: ^12.14 || ^13.3` is what closes it; the
     * `laravel/framework` entry is dev-only and binds nothing a user installs. Widening that floor is
     * survivable rather than fatal, but check here first: {@see classAttribute()} name-matches an attribute
     * whose class is absent and throws on `newInstance()`. Only a model naming a class its own framework
     * lacks gets there — code Laravel fatals on too, since `Error` escapes its `catch (Exception)`.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyTimestampsAttributes(\ReflectionClass $reflection, Model $instance): void
    {
        if ($instance->timestamps !== true) {
            return;
        }

        if (self::classAttribute($reflection, WithoutTimestamps::class) !== null) {
            $instance->timestamps = false;

            return;
        }

        $table = self::classAttribute($reflection, Table::class);
        if ($table !== null && $table->timestamps !== null) {
            $instance->timestamps = $table->timestamps;
        }
    }

    /**
     * `#[Connection(name:)]` fills a null `$connection` (`??=`), normalized to string like
     * `Model::getConnectionName()`'s `enum_value()`. Deliberately stronger than storing the raw enum: the
     * registry's `connection` field is `?string`, and an int-backed enum would TypeError under strict_types,
     * dropping the whole model via warmUp()'s catch.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyConnectionAttribute(\ReflectionClass $reflection, Model $instance): void
    {
        if ($instance->getConnectionName() !== null) {
            return;
        }

        $name = self::classAttribute($reflection, Connection::class)?->name;
        if ($name === null) {
            return;
        }

        $instance->setConnection(match (true) {
            \is_string($name) => $name,
            $name instanceof \BackedEnum => (string) $name->value,
            default => $name->name,
        });
    }

    /**
     * A `#[Table]` declared DIRECTLY on the concrete class (Laravel's non-recursive check) with no own
     * `$table` overwrites the table; otherwise the ancestor-walked name only fills a still-null one (`??=`).
     * Feeds {@see ModelMetadataRegistryBuilder::computeSchema()}'s migration lookup.
     *
     * Known gap: a `key`/`keyType`-only `#[Table]` (null name) does NOT clear an inherited `$table` the way
     * Laravel's force-branch does. Same deferred scenario as {@see self::applyFillableGuardedAttributes()}'s
     * PK sub-overrides, so left untouched rather than half-applied.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyTableAttribute(\ReflectionClass $reflection, Model $instance): void
    {
        $name = self::classAttribute($reflection, Table::class)?->name;
        if ($name === null) {
            return;
        }

        $forceSet = !self::declaresOwnProperty($reflection, 'table') && $reflection->getAttributes(Table::class) !== [];
        if ($forceSet || self::rawTableIsNull($instance)) {
            $instance->setTable($name);
        }
    }

    /**
     * Mirrors the `$declaresTable` check in `initializeModelAttributes()`.
     *
     * @param \ReflectionClass<Model> $reflection
     * @psalm-pure
     */
    private static function declaresOwnProperty(\ReflectionClass $reflection, string $name): bool
    {
        if (!$reflection->hasProperty($name)) {
            return false;
        }

        return $reflection->getProperty($name)->getDeclaringClass()->getName() === $reflection->getName();
    }

    /** Reads the raw property, not `getTable()`, which always derives a non-null name from the class. */
    private static function rawTableIsNull(Model $instance): bool
    {
        try {
            $property = new \ReflectionProperty($instance, 'table');
        } catch (\ReflectionException) {
            // Unreachable while Model declares `protected $table` (it does); defensive only.
            return true;
        }

        // A typed-uninitialized `$table` (non-idiomatic) has no value to read; treat it as null and avoid the
        // \Error getValue() throws on it.
        if (!$property->isInitialized($instance)) {
            return true;
        }

        // Consume the mixed value inline so it never binds to a local — keeps coverage at 100%.
        return $property->getValue($instance) === null;
    }

    /**
     * Mirrors {@see Model}::resolveClassAttribute(): first ancestor's first instance, no cross-ancestor merge.
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
