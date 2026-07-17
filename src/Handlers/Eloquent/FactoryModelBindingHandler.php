<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Auto-binds `@extends Factory<TModel>` on factory subclasses that follow
 * Laravel's naming convention but omit the docblock, e.g. a bare
 * `class TaskFactory extends Factory` (the stock layout in pterodactyl/panel,
 * bookstack, monica, ...).
 *
 * Effect: `TModel` resolves to the concrete model, so `(new TaskFactory())
 * ->create()`/`->make()` return `Task` instead of collapsing to base `Model`,
 * and `MissingTemplateParam` (the symptom #780 reports on Psalm 6) is silenced.
 *
 * Inverse of #517 (Model -> Factory) and the missing complement of
 * {@see ModelFactoryMethodTypeProvider}, whose Tier-2 gate
 * `hasModelTemplateBinding()` reads exactly the slot this handler fills.
 *
 * Runs on `AfterCodebasePopulated` (not `AfterClassLikeVisit`) because model
 * lineage on the resolved candidate is only trustworthy once the populator has
 * filled `parent_classes` for every class.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/780
 * @internal
 */
final class FactoryModelBindingHandler implements AfterCodebasePopulatedInterface
{
    private static ?string $appNamespaceCache = null;

    private static bool $appNamespaceResolved = false;

    public static function reset(): void
    {
        self::$appNamespaceCache = null;
        self::$appNamespaceResolved = false;

        // Flush Laravel's process-global factory resolver state so a prior app's
        // guessModelNamesUsing()/useNamespace() registrations cannot leak into
        // the next invocation's runtime path (repeated plugin initialization).
        if (\class_exists(Factory::class)) {
            Factory::flushState();
        }
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        // No Eloquent factories in the codebase (slim packages) -> skip the
        // full-codebase walk entirely.
        if (!$codebase->classlike_storage_provider->has(Factory::class)) {
            return;
        }

        $factoryFqcnLc = \strtolower(Factory::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            try {
                if (!self::isDirectFactorySubclass($storage, $factoryFqcnLc)) {
                    continue;
                }

                $modelFqcn = self::resolveModelFqcn($storage, $codebase);
                if ($modelFqcn !== null) {
                    self::injectBinding($storage, $modelFqcn);
                }
            } catch (\Throwable $throwable) {
                // Per-iteration guard: one corrupted storage entry must not
                // abort the pass for every other factory.
                $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : '(no message)';
                $codebase->progress->warning(
                    "Laravel plugin: factory TModel binding failed for '{$storage->name}' ("
                    . $throwable::class . '): ' . $message,
                );
            }
        }
    }

    /**
     * Only bare, concrete leaves whose DIRECT parent is `Factory` qualify.
     * The direct-parent guard keeps the #677 abstract-base pattern intact:
     * indirect descendants inherit a populator-supplied binding from their
     * abstract base, and re-binding here would override the polymorphic
     * contract the user designed.
     *
     * @psalm-mutation-free
     */
    private static function isDirectFactorySubclass(ClassLikeStorage $storage, string $factoryFqcnLc): bool
    {
        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return false;
        }

        if ($storage->parent_class === null || \strtolower($storage->parent_class) !== $factoryFqcnLc) {
            return false;
        }

        // User-defined `@template T of Model` on the factory -> defer to their
        // polymorphism.
        if ($storage->template_types !== null && $storage->template_types !== []) {
            return false;
        }

        // An explicit `@extends Factory<...>` is a real user contract, whatever
        // it binds TModel to — including a deliberate `@extends Factory<Model>`
        // polymorphic base. Psalm's scanner sets template_type_extends_count
        // only when the docblock is present; an omitted docblock leaves it null
        // (the populator then fills TModel with the default bare `Model`, which
        // we DO want to override). Presence => hands off.
        return ($storage->template_type_extends_count[Factory::class] ?? null) === null;
    }

    /**
     * Runtime path first (autoloadable factories in real apps), static
     * fallback for classes Psalm scanned but PHP cannot load (phpt inline
     * classes).
     */
    private static function resolveModelFqcn(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        return self::resolveFromRuntime($storage, $codebase)
            ?? self::resolveFromModelProperty($storage, $codebase)
            ?? self::resolveFromShortName($storage->name, $codebase);
    }

    /**
     * Primary path (autoloadable factories in real apps): let Laravel's own
     * `Factory::modelName()` resolve the target. This honors `#[UseModel]`,
     * the `$model` property, the naming convention, AND container overrides
     * (`Factory::useNamespace()`, `guessModelNamesUsing()`) that the static
     * fallback cannot see.
     */
    private static function resolveFromRuntime(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        $factoryFqcn = $storage->name;

        if (!\class_exists($factoryFqcn) || !\is_a($factoryFqcn, Factory::class, true)) {
            return null;
        }

        // A factory declaring its OWN `__construct` may assign `$this->model`
        // there; `newInstanceWithoutConstructor()` skips it, so `modelName()`
        // would read a null/stale model. Defer to the static tiers, which read
        // the declared property/shortname from storage, not a live instance.
        if (isset($storage->methods['__construct'])) {
            return null;
        }

        // No constructor: `modelName()` reads only the `$model` default, the
        // `#[UseModel]` attribute, and static resolver state — none need
        // `__construct` (faker/collections). Also sidesteps UnsafeInstantiation
        // on the non-final Factory ctor. `modelName()` is untyped (mixed);
        // validate inline so no mixed local leaks into coverage.
        //
        // Local catch: a throwing `modelName()` (factory names an absent model,
        // or a user `guessModelNamesUsing` callback throws) must degrade to the
        // static tiers, not abandon the factory via the outer per-iteration catch.
        try {
            $factory = (new \ReflectionClass($factoryFqcn))->newInstanceWithoutConstructor();

            return self::validateModel($factory->modelName(), $codebase);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Static fallback (a): read the `$model` property default. An untyped
     * `protected $model = X::class;` (the pterodactyl/bookstack shape) has no
     * declared `type`; Psalm records the inferred default in `suggested_type`
     * as a `TLiteralClassString`. Kept lean — the `@var class-string<X>` shape
     * is covered by the runtime path in real apps.
     *
     * @psalm-mutation-free
     */
    private static function resolveFromModelProperty(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        $property = $storage->properties['model'] ?? null;
        $type = $property->type ?? $property->suggested_type ?? null;
        if (!$type instanceof Union) {
            return null;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TLiteralClassString && self::isModelClass($atomic->value, $codebase)) {
                return $atomic->value;
            }
        }

        return null;
    }

    /**
     * Static fallback (b): strip the `Factory` suffix from the shortname and
     * prepend the app's model namespace, honoring sub-namespaces nested under
     * `Database\Factories\` (`Database\Factories\Auth\UserFactory` ->
     * `App\Models\Auth\User`). Falls back to the flat `{AppNs}{Base}` form for
     * apps that flatten their model namespace.
     */
    private static function resolveFromShortName(string $factoryFqcn, Codebase $codebase): ?string
    {
        $lastSep = \strrpos($factoryFqcn, '\\');
        $shortName = $lastSep === false ? $factoryFqcn : \substr($factoryFqcn, $lastSep + 1);

        if ($shortName === 'Factory' || !\str_ends_with($shortName, 'Factory')) {
            return null;
        }

        $appNamespace = self::resolveAppNamespace();
        if ($appNamespace === null) {
            return null;
        }

        $flatBase = \substr($shortName, 0, -\strlen('Factory'));

        // Strip the first `Database\Factories\` occurrence to recover any
        // nested sub-namespace, mirroring Laravel's Str::replaceFirst().
        $nestedBase = null;
        $pos = \strpos($factoryFqcn, 'Database\\Factories\\');
        if ($pos !== false) {
            $nestedBase = \substr(\substr($factoryFqcn, $pos + \strlen('Database\\Factories\\')), 0, -\strlen('Factory'));
        }

        // Laravel's default resolver (Factory::modelName()) tries EXACTLY two
        // candidates, in order:
        //   1. {AppNs}Models\{nestedBase} — factory sub-namespace under
        //      Database\Factories\, nested under Models\ (or the flat basename
        //      when unnested).
        //   2. {AppNs}{flatBase} — the bare factory basename, no Models\.
        // No other combination is valid: {AppNs}Models\{flatBase} would wrongly
        // match a flat model when a nested one was intended.
        $namespacedBase = $nestedBase !== null && $nestedBase !== '' ? $nestedBase : $flatBase;

        $candidates = [
            $appNamespace . 'Models\\' . $namespacedBase,
            $appNamespace . $flatBase,
        ];

        foreach ($candidates as $candidate) {
            if (self::isModelClass($candidate, $codebase)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Cached for the analysis lifetime — `getNamespace()` walks composer.json. */
    private static function resolveAppNamespace(): ?string
    {
        if (self::$appNamespaceResolved) {
            return self::$appNamespaceCache;
        }

        // Flip before the lookup so a thrown RuntimeException memoizes the
        // failure instead of re-running for every factory class.
        self::$appNamespaceResolved = true;

        try {
            return self::$appNamespaceCache = ApplicationProvider::getApp()->getNamespace();
        } catch (\RuntimeException) {
            return self::$appNamespaceCache = null;
        }
    }

    /**
     * @param mixed $candidate
     * @psalm-mutation-free
     */
    private static function validateModel($candidate, Codebase $codebase): ?string
    {
        if (!\is_string($candidate) || $candidate === '') {
            return null;
        }

        return self::isModelClass($candidate, $codebase) ? $candidate : null;
    }

    /**
     * A resolved candidate must be a scanned Model subclass — never guess a
     * binding for a class Psalm has not verified.
     *
     * @psalm-mutation-free
     */
    private static function isModelClass(string $fqcn, Codebase $codebase): bool
    {
        if (!$codebase->classlike_storage_provider->has($fqcn)) {
            return false;
        }

        return isset($codebase->classlike_storage_provider->get($fqcn)->parent_classes[\strtolower(Model::class)]);
    }

    /**
     * Merge TModel into `template_extended_params[Factory::class]`, preserving
     * the populator-supplied TCount (which gates the `(TCount is null|0|1 ?
     * TModel : Collection<int, TModel>)` conditional return on create()/make()),
     * and set the extends-count to 2 so both params read as bound.
     */
    private static function injectBinding(ClassLikeStorage $storage, string $modelFqcn): void
    {
        $params = $storage->template_extended_params ?? [];
        $existing = $params[Factory::class] ?? [];
        $existing['TModel'] = new Union([new TNamedObject($modelFqcn)]);
        $params[Factory::class] = $existing;

        $counts = $storage->template_type_extends_count ?? [];
        $counts[Factory::class] = 2;

        $storage->template_extended_params = $params;
        $storage->template_type_extends_count = $counts;
    }
}
