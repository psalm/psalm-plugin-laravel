<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Auto-injects the `@extends Factory<TModel>` binding for factory subclasses that
 * follow Laravel's naming convention but omit the docblock annotation.
 *
 * Mirrors the runtime resolution in
 * {@see \Illuminate\Database\Eloquent\Factories\Factory::modelName()}:
 *
 *   1. If `#[UseModel(SomeModel::class)]` attribute is present, use that.
 *   2. Else if `protected $model = SomeModel::class;` is declared, use that.
 *   3. Else strip the trailing `Factory` from the class shortname and look it up
 *      under the app namespace as `{AppNs}Models\{ShortName}` (with the
 *      `{AppNs}{ShortName}` flat fallback matching the runtime closure for
 *      apps that flatten their model namespace).
 *   4. Verify the candidate class is a Model subclass via `classlike_storage_provider`.
 *   5. Mutate the factory's `ClassLikeStorage` so Psalm's parent-extension check
 *      treats it as `Factory<ResolvedModel>`. The populator-supplied `TCount`
 *      binding is preserved so the conditional return type
 *      `(TCount is null|0|1 ? TModel : Collection<int, TModel>)` keeps working.
 *
 * This is the inverse of #517 (Model → Factory) and the complement of #964
 * (which reads `template_extended_params[Factory::class]['TModel']` on the
 * factory side to resolve `Model::factory()` for bare `use HasFactory;`).
 * Without this handler, real Laravel apps that omit `@extends Factory<X>` (the
 * common case — pterodactyl/panel, bookstack, monica, vito, etc.) leak
 * `MissingTemplateParam` on every factory and #964's Tier-2 lookup is a no-op.
 *
 * Skipped when:
 *  - The factory already binds TModel via docblock (`template_extended_offsets`
 *    set, the scan-time source for `@extends Factory<X>`).
 *  - The factory declares its own templates (`@template T of Model`) — defer to
 *    user-defined polymorphism.
 *  - The factory is abstract (the #677 abstract-base pattern — leaves only).
 *  - The factory's direct parent is not `Factory` itself (indirect descendants
 *    inherit a binding through the populator from their abstract base).
 *  - No model FQCN resolves via UseModel, the `$model` property, or the
 *    shortname convention.
 *
 * Container-dependent overrides (`Factory::guessModelNamesUsing()`,
 * `Factory::useNamespace()`) are intentionally not honored — they require a
 * booted container with the user's service providers loaded, which the plugin
 * cannot reliably guarantee at scan time. The default convention covers the
 * stock Laravel layout used by virtually all real-world projects.
 *
 * Hooked on `AfterCodebasePopulated` rather than `AfterClassLikeVisit` because
 * we need verified Model lineage on the candidate FQCN, which is only available
 * after the populator has filled `parent_classes` for every class.
 *
 * @internal
 */
final class FactoryTemplateInjectionHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        // Skip the full-codebase iteration entirely on projects that don't have
        // Eloquent factories at all (slim packages, library codebases). The
        // is_interface/is_trait/parent_class triage below is cheap, but
        // skipping it on the negative case is free.
        if (!$codebase->classlike_storage_provider->has(Factory::class)) {
            return;
        }

        $factoryFqcnLc = \strtolower(Factory::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            try {
                if ($storage->is_interface || $storage->is_trait) {
                    continue;
                }

                // Direct-parent guard first: rejects ~99% of remaining classes
                // with a single null check + string compare, avoiding the
                // `strtolower()` cost for every classlike with no parent.
                // Only handle classes whose direct parent is `Factory` itself.
                // Indirect descendants (e.g. `UserFactory extends MyBaseFactory`
                // where `MyBaseFactory` is an abstract `Factory<TModel>`, the
                // #677 pattern) inherit a populator-supplied `template_extended_params`
                // mapping for `Factory::class` from the abstract base. Re-binding
                // it here would override the polymorphic contract the user designed.
                if ($storage->parent_class === null
                    || \strtolower($storage->parent_class) !== $factoryFqcnLc) {
                    continue;
                }

                if ($storage->abstract) {
                    continue;
                }

                if (self::alreadyBindsTModel($storage)) {
                    continue;
                }

                // User-defined `@template T of Model` on the factory subclass —
                // defer to whatever polymorphism the user intended.
                if ($storage->template_types !== null && $storage->template_types !== []) {
                    continue;
                }

                $modelFqcn = self::resolveModelFqcn($storage, $codebase);
                if ($modelFqcn === null) {
                    continue;
                }

                self::injectBinding($storage, $modelFqcn);
            } catch (\Error|\Exception $error) {
                // Per-iteration guard mirrors ModelRegistrationHandler — a
                // single corrupted storage entry should not abort the entire
                // AfterCodebasePopulated pass for every other factory. Include
                // the exception class because `\Error` and Psalm-internal
                // exceptions often have terse or empty messages on their own.
                $message = $error->getMessage() !== '' ? $error->getMessage() : '(no message)';
                $codebase->progress->warning(
                    "Laravel plugin: factory TModel injection failed for '{$storage->name}' ("
                    . $error::class . '): ' . $message,
                );
            }
        }
    }

    /**
     * True when the factory carries an explicit user-defined TModel binding
     * for `Factory::class`. `template_extended_offsets` is the scan-time
     * source for `@extends Factory<X>` docblocks; `template_extended_params`
     * cannot be checked directly because the populator fills it with the
     * parent template's default constraint (`Model`) for unbound subclasses,
     * which is exactly the case we want to override.
     *
     * @psalm-mutation-free
     */
    private static function alreadyBindsTModel(ClassLikeStorage $storage): bool
    {
        return !empty($storage->template_extended_offsets[Factory::class]);
    }

    private static function resolveModelFqcn(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        $fromAttribute = self::resolveFromUseModelAttribute($storage, $codebase);
        if ($fromAttribute !== null) {
            return $fromAttribute;
        }

        $fromProperty = self::resolveFromModelProperty($storage, $codebase);
        if ($fromProperty !== null) {
            return $fromProperty;
        }

        return self::resolveFromShortName($storage->name, $codebase);
    }

    /**
     * Mirror of the runtime `cachedModelAttributes` branch in
     * `Factory::modelName()`. The `#[UseModel(ModelClass::class)]` attribute
     * landed in Laravel 11 and is the user's strongest signal: it overrides
     * the convention and the `$model` property. Its argument is a
     * `class-string<TModel>` literal that Psalm parses as a
     * `TLiteralClassString` Union.
     *
     * @psalm-mutation-free
     */
    private static function resolveFromUseModelAttribute(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name !== UseModel::class) {
                continue;
            }

            $firstArg = $attribute->args[0] ?? null;
            if ($firstArg === null || !$firstArg->type instanceof Union) {
                continue;
            }

            $candidate = self::extractClassFromUnion($firstArg->type);
            if ($candidate !== null && self::isModelClass($candidate, $codebase)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Mirror of the runtime `$factory->model !== null` branch. The `$model`
     * property is typically declared as `protected $model = X::class;` without
     * a docblock — Psalm parses that default as a `TLiteralClassString`.
     * When the user adds a `@var class-string<X>` docblock, Psalm stores a
     * `TClassString` with the constraint class in `$as`. Both shapes resolve
     * to the same target.
     *
     * @psalm-mutation-free
     */
    private static function resolveFromModelProperty(ClassLikeStorage $storage, Codebase $codebase): ?string
    {
        $property = $storage->properties['model'] ?? null;
        if ($property === null) {
            return null;
        }

        // Prefer the declared/docblock type when present; fall back to the
        // default-inferred type so `protected $model = X::class;` (no docblock)
        // still resolves.
        $type = $property->type ?? $property->suggested_type;
        if (!$type instanceof Union) {
            return null;
        }

        $candidate = self::extractClassFromUnion($type);
        if ($candidate !== null && self::isModelClass($candidate, $codebase)) {
            return $candidate;
        }

        return null;
    }

    /**
     * Pull a single FQCN out of either of the two shapes Psalm uses for a
     * class-string Union: `TLiteralClassString` (literal `X::class`) and
     * `TClassString` with a non-`object` constraint (`class-string<X>` from a
     * docblock).
     *
     * @psalm-mutation-free
     */
    private static function extractClassFromUnion(Union $type): ?string
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TLiteralClassString) {
                return $atomic->value;
            }

            if ($atomic instanceof TClassString && $atomic->as !== 'object') {
                return $atomic->as;
            }
        }

        return null;
    }

    /**
     * Convention closure from Laravel's `Factory::modelName()`:
     *
     *   $namespacedBase = Str::replaceLast('Factory', '', Str::replaceFirst(static::$namespace, '', $factory::class));
     *   $factoryBase    = Str::replaceLast('Factory', '', class_basename($factory));
     *   $appNs          = static::appNamespace();
     *   return class_exists($appNs.'Models\\'.$namespacedBase)
     *       ? $appNs.'Models\\'.$namespacedBase
     *       : $appNs.$factoryBase;
     *
     * `Str::replaceFirst` strips the first occurrence of the factory namespace
     * anywhere in the FQCN — so `Database\Factories\Auth\UserFactory` and
     * any prefix variant become `Auth\UserFactory`, then `Auth\User` after the
     * suffix strip. The plugin checks both the namespaced and flat-basename
     * forms because Laravel's runtime probes both via `class_exists`.
     *
     * `Factory::useNamespace()` lets apps override `Factory::$namespace` from
     * its default `'Database\\Factories\\'`. Honoring that override needs the
     * booted container's service-provider chain, which the plugin can't
     * reliably read at scan time — see class docblock for the trade-off.
     */
    private static function resolveFromShortName(string $factoryFqcn, Codebase $codebase): ?string
    {
        $shortName = self::classBasename($factoryFqcn);

        if ($shortName === 'Factory' || !\str_ends_with($shortName, 'Factory')) {
            return null;
        }

        $factoryBasename = \substr($shortName, 0, -\strlen('Factory'));
        if ($factoryBasename === '') {
            return null;
        }

        $appNamespace = self::resolveAppNamespace($codebase);
        if ($appNamespace === null) {
            return null;
        }

        // Mirror Str::replaceFirst('Database\\Factories\\', '', $fqcn):
        // strip the first occurrence anywhere, not just at the start.
        $namespacedBase = self::stripFactoryNamespace($factoryFqcn);
        if ($namespacedBase !== null && \str_ends_with($namespacedBase, 'Factory')) {
            $namespacedBase = \substr($namespacedBase, 0, -\strlen('Factory'));
        }

        if ($namespacedBase === '') {
            $namespacedBase = null;
        }

        // Candidate order matches Laravel's runtime preference: namespaced
        // (with Models\ prefix) first, then the flat fallback. Deduplication
        // skips redundant lookups when $namespacedBase === $factoryBasename
        // (the common Database\Factories\UserFactory case).
        $candidates = [];
        if ($namespacedBase !== null) {
            $candidates[] = $appNamespace . 'Models\\' . $namespacedBase;
        }

        $candidates[] = $appNamespace . 'Models\\' . $factoryBasename;
        if ($namespacedBase !== null) {
            $candidates[] = $appNamespace . $namespacedBase;
        }

        $candidates[] = $appNamespace . $factoryBasename;

        foreach (\array_unique($candidates) as $candidate) {
            if (self::isModelClass($candidate, $codebase)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @psalm-pure */
    private static function classBasename(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : \substr($fqcn, $pos + 1);
    }

    /**
     * Mirror Laravel's `Str::replaceFirst('Database\\Factories\\', '', $fqcn)`:
     * strip the first occurrence wherever it appears in the FQCN. Returns null
     * when the prefix is absent — callers then fall back to the flat basename.
     *
     * @psalm-pure
     */
    private static function stripFactoryNamespace(string $factoryFqcn): ?string
    {
        $needle = 'Database\\Factories\\';
        $pos = \strpos($factoryFqcn, $needle);
        if ($pos === false) {
            return null;
        }

        return \substr($factoryFqcn, $pos + \strlen($needle));
    }

    private static ?string $appNamespaceCache = null;

    private static bool $appNamespaceResolved = false;

    /**
     * Read the app namespace from the booted Laravel app. Cached for the
     * lifetime of the analysis: `getNamespace()` walks composer.json and
     * matches PSR-4 entries; cheap, but called once per factory class without
     * memoization can add up on large codebases.
     *
     * The flag flip (`$appNamespaceResolved = true`) happens BEFORE the lookup
     * so a thrown exception still memoizes the failure — without it, a
     * `RuntimeException` on every factory class would re-invoke the lookup
     * (and re-emit the warning) for the rest of the analysis.
     *
     * On `RuntimeException` (the documented failure mode of
     * `Application::getNamespace()` — malformed composer.json, missing PSR-4
     * entry) we emit a one-shot warning. Other throwables are not caught:
     * they indicate plugin bugs that should surface, not silent degradation.
     */
    private static function resolveAppNamespace(Codebase $codebase): ?string
    {
        if (self::$appNamespaceResolved) {
            return self::$appNamespaceCache;
        }

        self::$appNamespaceResolved = true;

        try {
            $app = ApplicationProvider::getApp();

            if (!\method_exists($app, 'getNamespace')) {
                return self::$appNamespaceCache = null;
            }

            return self::$appNamespaceCache = $app->getNamespace();
        } catch (\RuntimeException $runtimeException) {
            $codebase->progress->warning(
                'Laravel plugin: factory TModel injection disabled — app namespace unresolved: '
                . $runtimeException->getMessage(),
            );

            return self::$appNamespaceCache = null;
        }
    }

    /**
     * Verify a candidate FQCN refers to a real Model subclass in the scanned
     * codebase. Uses `has()` (an O(1) `isset` check) before `get()` to avoid
     * the `InvalidArgumentException` throw/catch path — `resolveFromShortName`
     * probes multiple candidates and allocating an exception object with a
     * full backtrace for each miss is the dominant cost on large codebases.
     *
     * @psalm-mutation-free
     */
    private static function isModelClass(string $fqcn, Codebase $codebase): bool
    {
        if (!$codebase->classlike_storage_provider->has($fqcn)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($fqcn);

        $modelFqcnLc = \strtolower(Model::class);

        // Degenerate but legal: a factory whose target is the abstract Model
        // class itself. All other paths require a real subclass.
        if (\strtolower($storage->name) === $modelFqcnLc) {
            return true;
        }

        return isset($storage->parent_classes[$modelFqcnLc]);
    }

    /**
     * Three fields must be set for the parent-extension check in
     * `ClassAnalyzer::analyzeClassExtends` to treat the factory as
     * `Factory<TModel>` without raising `MissingTemplateParam`:
     *
     *   - `template_type_extends_count[Factory::class] = 2` matches the
     *     two-template stub (`@template TModel of Model`, `@template TCount`),
     *     gating the `MissingTemplateParam` check at its `$given_param_count`
     *     source (vimeo/psalm `ClassLikeAnalyzer::checkTemplateParams`).
     *   - `template_extended_params[Factory::class]['TModel']` drives template
     *     substitution during downstream analysis (method/property type
     *     resolution, FactoryCountTypeProvider, etc.).
     *   - `template_extended_params[Factory::class]['TCount']` is the
     *     populator-supplied default that gates the
     *     `(TCount is null|0|1 ? TModel : Collection<int, TModel>)` conditional
     *     return on `create()`/`make()`. The populator filled it in for any
     *     factory without an `@extends` docblock — we MERGE rather than
     *     overwrite to keep it in place.
     *
     * Bypassing `template_extended_offsets` is intentional: the populator
     * has already run by this point and writing offsets would not re-trigger
     * inheritance propagation. For direct `extends Factory` (the only case
     * this handler targets, see class docblock), writing the final
     * `template_extended_params` is equivalent and the analysis pass picks
     * it up.
     */
    private static function injectBinding(ClassLikeStorage $storage, string $modelFqcn): void
    {
        $modelUnion = new Union([new TNamedObject($modelFqcn)]);

        // Build both target maps locally before mutating the storage so a thrown
        // error mid-build leaves the storage untouched. Without this, an
        // exception between the two `$storage->...` writes would leave the
        // factory with `TModel` bound but `template_type_extends_count` still
        // 0, surfacing as a confusing `MissingTemplateParam` on a factory whose
        // params map shows it IS bound.
        $params = $storage->template_extended_params ?? [];

        // Merge into any existing populator-supplied entry (notably `TCount`)
        // rather than replacing the slot — replacing would drop the conditional
        // return wiring described above.
        $existing = $params[Factory::class] ?? [];
        $existing['TModel'] = $modelUnion;
        $params[Factory::class] = $existing;

        $counts = $storage->template_type_extends_count ?? [];
        $counts[Factory::class] = 2;

        $storage->template_extended_params = $params;
        $storage->template_type_extends_count = $counts;
    }
}
