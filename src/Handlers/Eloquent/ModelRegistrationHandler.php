<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AttributeMutatorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CustomTypeDetector;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Internal\AnonymousClassNameDetector;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Discovers Eloquent model classes from Psalm's scanned codebase and registers
 * property and method handlers for each discovered model.
 *
 * This replaces directory-based model scanning: instead of pre-scanning directories
 * for model files, we wait until Psalm has populated its codebase with all project
 * classes, then register handlers for every Model subclass found.
 *
 * Registers per-model:
 * - Method existence, visibility, params, and return types ({@see ModelMethodHandler})
 * - Property existence, visibility, and types (relationships, accessors, columns)
 *
 * Abstract base models register the storage-based handlers (method forwarding, relationship/
 * accessor/aggregate/factory properties, return-type providers) so a scope, forwarded builder
 * method, or inherited virtual property resolves on an abstract-typed receiver (issue #901).
 * Two groups stay concrete-only: the migration column/cast handler (reads getTable()/getCasts()
 * off a model instance, which an abstract class cannot provide) and custom builder/collection
 * detection (an abstract base falls back to the base Builder/Collection — detecting a custom
 * builder there would strip the model's SoftDeletes pseudo-methods and regress base-Builder
 * resolution; see registerHandlersForModel()).
 *
 * @internal
 */
final class ModelRegistrationHandler implements AfterCodebasePopulatedInterface
{
    private static bool $useMigrations = false;

    private static bool $modelToArrayShapeEnabled = false;

    /** @psalm-external-mutation-free */
    public static function enableMigrations(): void
    {
        self::$useMigrations = true;
    }

    /** @psalm-external-mutation-free */
    public static function enableModelToArrayShape(): void
    {
        self::$modelToArrayShapeEnabled = true;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $modelFqcn = \strtolower(Model::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!isset($storage->parent_classes[$modelFqcn])) {
                continue;
            }

            // Abstract bases are intentionally NOT skipped here (see the class docblock for the
            // storage-vs-instance split). The pre-#901 wholesale `if ($storage->abstract) continue;`
            // guard lived at this spot — removing it is what lets the storage-based handlers register
            // for abstract bases. https://github.com/psalm/psalm-plugin-laravel/issues/901

            // Anonymous Model subclasses (e.g. `new class extends Model {}`) get a
            // synthetic FQCN from Psalm that no autoloader can resolve, so skip them
            // before class_exists() to avoid a misleading warning. Psalm leaves
            // $storage->location null for anonymous classes (there is no name node
            // to locate); stmt_location carries the `class { ... }` position and is
            // the correct source of the declaring file path.
            if (
                $storage->stmt_location !== null
                && AnonymousClassNameDetector::isSynthetic($storage->name, $storage->stmt_location->file_path)
            ) {
                continue;
            }

            // Force-load the class via Composer's autoloader so that runtime
            // reflection works in property handlers (e.g. getTable(), getCasts())
            try {
                if (!\class_exists($storage->name, true)) {
                    $codebase->progress->warning(
                        "Laravel plugin: skipping model '{$storage->name}': class could not be loaded by autoloader",
                    );
                    continue;
                }
            } catch (\Error|\Exception $error) {
                $codebase->progress->warning(
                    "Laravel plugin: skipping model '{$storage->name}': {$error->getMessage()}",
                );
                continue;
            }

            // Warm the registry FIRST (idempotent, never throws) for every model — concrete and
            // abstract bases alike (#1058), not migration-gated (ModelPropertyAccessorHandler reads
            // accessors()/mutators() unconditionally). Must precede registerHandlersForModel so the
            // latter reads the resolved customBuilder/customCollection off the registry instead of
            // reflecting a SECOND time (Gotcha 8). Safe reorder: compute() reads only real-method +
            // reflection state, never the pseudo_* maps registerHandlersForModel mutates.
            ModelMetadataRegistryBuilder::warmUp($codebase, $storage->name);

            self::registerHandlersForModel($codebase, $storage);

            // Mutator + relation write-types run after warmUp + registration: the mutator branch reads
            // mutators() (populated by warmUp); relation-write keeps its own declared-return-type
            // detection. registerWriteTypesForColumns ran earlier, inside registerHandlersForModel.
            self::registerWriteTypesForMethods($codebase, $storage);
        }
    }

    private static function registerHandlersForModel(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $className = $storage->name;
        $properties = $codebase->properties;
        $methods = $codebase->methods;

        // Drop pseudo_static_methods that shadow real method declarations. Traits can declare
        // `@method static Builder query()` (e.g. Koel's SupportsDeleteWhereValueNotIn); this
        // injects a zero-param pseudo into every model that uses the trait. Psalm's static
        // call analyzer checks pseudo_static_methods first for argument validation, so the
        // pseudo rejects Song::query(type: $t, user: $u) with TooManyArguments even though
        // Song declares `public static function query(?PlayableType $t, ?User $u)`. The same
        // shadowing applies to Model's other static helpers (on, onWriteConnection, with, ...),
        // so drop any pseudo whose name also corresponds to a real declaration on the class
        // (declared here, inherited from Model, or imported via another trait); declaring_method_ids
        // lists every such real declaration.
        //
        // Runs here (post-populator) rather than in an AfterClassLikeVisit hook because
        // Populator::populateDataFromTrait() merges trait pseudo_static_methods into the
        // model's storage AFTER the scan phase, so earlier removal is a no-op.
        //
        // Issue: https://github.com/psalm/psalm-plugin-laravel/issues/795
        foreach (\array_keys($storage->pseudo_static_methods) as $methodName) {
            if (isset($storage->declaring_method_ids[$methodName])) {
                unset($storage->pseudo_static_methods[$methodName]);
            }
        }

        // Custom builder/collection come from the warmed registry — no reflection here (Gotcha 8:
        // kills the second per-model reflection the old detectCustom*() wrappers did). On the rare
        // warm-up failure (logged by warmUp()) the entry is absent, so re-resolve them below from
        // reflection (the only reflection-derived fields; the rest of the entry stays missing).
        /** @var class-string<Model> $className — verified by parent_classes check in caller */
        $metadata = ModelMetadataRegistry::for($className);

        // Abstract bases skip the custom builder: a non-null result drives handleTraitBuilderMethods(),
        // which STRIPS the SoftDeletes @method pseudo-methods (withTrashed/onlyTrashed) so they resolve
        // through the custom builder — but an abstract base is queried through base Builder<AbstractBase>,
        // where BuilderScopeHandler reads those pseudo-methods, so stripping regresses to mixed (#901).
        // Concrete children use their detected builder; abstract bases fall back to base Builder, a sound
        // supertype. See AbstractModelCustomBuilderTest.
        if ($storage->abstract) {
            $customBuilder = null;
        } else {
            $customBuilder = $metadata instanceof ModelMetadata
                ? $metadata->customBuilder
                : CustomTypeDetector::resolveCustomBuilderClass($codebase, $className);
        }

        // Custom-builder models: register the model→builder map and remap trait @method static macros
        // (e.g. SoftDeletes::withTrashed returning Builder<static>) to the custom builder type.
        if ($customBuilder !== null) {
            ModelMethodHandler::registerCustomBuilder($className, $customBuilder);
            self::handleTraitBuilderMethods($codebase, $storage, $className, $customBuilder);

            // Register scope handlers for the custom builder class so that builder
            // instance calls like Post::query()->featured() resolve correctly.
            // BuilderScopeHandler only covers base Builder — custom subclasses need
            // explicit registration. See https://github.com/psalm/psalm-plugin-laravel/issues/630
            $methods->existence_provider->registerClosure(
                $customBuilder,
                CustomBuilderMethodHandler::doesScopeMethodExistOnBuilder(...),
            );
            $methods->visibility_provider->registerClosure(
                $customBuilder,
                CustomBuilderMethodHandler::isScopeMethodVisibleOnBuilder(...),
            );
            $methods->params_provider->registerClosure(
                $customBuilder,
                CustomBuilderMethodHandler::getScopeMethodParamsOnBuilder(...),
            );
            $methods->return_type_provider->registerClosure(
                $customBuilder,
                CustomBuilderMethodHandler::getScopeMethodReturnTypeOnBuilder(...),
            );
        }

        // For base-Builder models: register trait-declared builder methods (e.g., SoftDeletes::withTrashed)
        // with BuilderScopeHandler so builder instance calls like Customer::query()->withTrashed() resolve.
        // At runtime these are macros registered via global scopes (SoftDeletingScope::extend).
        // BuilderScopeHandler needs both the return type and the params to avoid crashing Psalm's
        // checkMethodArgs when it looks up Builder::withTrashed params.
        // Scan every base-Builder model: different models may carry different builder-returning trait
        // methods, so we must not stop early. The += merge keeps the first-seen signature for any
        // given method name, which is correct since trait signatures are uniform across models.
        // See https://github.com/psalm/psalm-plugin-laravel/issues/635
        if ($customBuilder === null) {
            $traitMethods = self::extractBuilderReturningMethods($storage);
            if ($traitMethods !== []) {
                BuilderScopeHandler::registerBaseBuilderTraitMethods($traitMethods);
            }
        }

        // Method existence, visibility, and return types for static __callStatic forwarding.
        // Registered per-model because Psalm's provider lookup uses exact class names —
        // a handler for Model::class is not consulted for App\Models\User. Storage-based (reads
        // method/class storage, never instantiates), so it registers for abstract bases too — this
        // is what lets a scope or forwarded Query\Builder method resolve on an abstract-typed
        // receiver (issue #901).
        $methods->existence_provider->registerClosure($className, ModelMethodHandler::doesMethodExist(...));
        $methods->visibility_provider->registerClosure($className, ModelMethodHandler::isMethodVisible(...));
        $methods->params_provider->registerClosure($className, ModelMethodHandler::getMethodParams(...));
        $methods->return_type_provider->registerClosure(
            $className,
            ModelMethodHandler::getReturnTypeForForwardedMethod(...),
        );

        // Custom collection: same registry read + warm-up-failure fallback as the builder above
        // (Gotcha 8). Abstract bases fall back to the base Eloquent collection, mirroring the builder.
        if (!$storage->abstract) {
            $customCollection = $metadata instanceof ModelMetadata
                ? $metadata->customCollection
                : CustomTypeDetector::resolveCustomCollectionClass($codebase, $className);

            if ($customCollection !== null) {
                CustomCollectionHandler::registerCustomCollection($className, $customCollection);
            }
        }

        // Custom collection: narrows Model::all() return type for models using
        // #[CollectedBy] or overriding newCollection() with a concrete subclass.
        $methods->return_type_provider->registerClosure(
            $className,
            CustomCollectionHandler::getModelMethodReturnType(...),
        );
        // Relationship method return types: precise generics for hasOne/belongsTo/etc.
        // Registered AFTER the forwarding/collection providers because those handlers
        // already returned null for relation method names. The first non-null result
        // wins, so safe ordering — no return-type swap relative to the prior chain.
        // See https://github.com/psalm/psalm-plugin-laravel/issues/760
        $methods->return_type_provider->registerClosure($className, ModelRelationReturnTypeHandler::getReturnType(...));
        // Model::only() shape narrowing from literal keys.
        // See https://github.com/psalm/psalm-plugin-laravel/issues/931
        $methods->return_type_provider->registerClosure($className, ModelAttributeSubsetHandler::getReturnType(...));
        // attributesToArray()/toArray() precise array-shape inference (columns + $appends, honoring
        // $hidden/$visible). Experimental — see https://github.com/psalm/psalm-plugin-laravel/issues/923
        // and the modelToArrayShape entry in docs/config.md.
        if (self::$modelToArrayShapeEnabled) {
            $methods->return_type_provider->registerClosure($className, ModelToArrayShapeHandler::getReturnType(...));
        }

        // Registration order matters — the first non-null result wins.

        // 1. Relationship properties (e.g. $user->posts)
        $properties->property_existence_provider->registerClosure(
            $className,
            ModelRelationshipPropertyHandler::doesPropertyExist(...),
        );
        $properties->property_visibility_provider->registerClosure(
            $className,
            ModelRelationshipPropertyHandler::isPropertyVisible(...),
        );
        $properties->property_type_provider->registerClosure(
            $className,
            ModelRelationshipPropertyHandler::getPropertyType(...),
        );

        // 2. Aggregate accessor properties (e.g. $label->contacts_count via withCount())
        $properties->property_existence_provider->registerClosure(
            $className,
            ModelAggregatePropertyHandler::doesPropertyExist(...),
        );
        $properties->property_visibility_provider->registerClosure(
            $className,
            ModelAggregatePropertyHandler::isPropertyVisible(...),
        );
        $properties->property_type_provider->registerClosure(
            $className,
            ModelAggregatePropertyHandler::getPropertyType(...),
        );

        // 3. Factory property ($model::factory())
        $properties->property_type_provider->registerClosure(
            $className,
            ModelFactoryTypeProvider::getPropertyType(...),
        );

        // 4. Accessor properties (e.g. $user->full_name via attribute accessor)
        $properties->property_existence_provider->registerClosure(
            $className,
            ModelPropertyAccessorHandler::doesPropertyExist(...),
        );
        $properties->property_visibility_provider->registerClosure(
            $className,
            ModelPropertyAccessorHandler::isPropertyVisible(...),
        );
        $properties->property_type_provider->registerClosure(
            $className,
            ModelPropertyAccessorHandler::getPropertyType(...),
        );

        // 5. Column properties from migrations (e.g. $user->email).
        // Concrete-only — this is the ONE handler that instantiates: ModelPropertyHandler reflects
        // on a model INSTANCE (newInstanceWithoutConstructor() → getTable()/getCasts()) to map
        // columns and casts. An abstract base cannot be instantiated and has no table, so it is
        // gated out here (issue #901). Every handler above is storage-based and registers for
        // abstract bases too. The resolveTableName()/resolveCasts() abstract guards are therefore
        // pure defense-in-depth — unreachable through this gated registration.
        if (!$storage->abstract && self::$useMigrations) {
            $properties->property_existence_provider->registerClosure(
                $className,
                ModelPropertyHandler::doesPropertyExist(...),
            );
            $properties->property_visibility_provider->registerClosure(
                $className,
                ModelPropertyHandler::isPropertyVisible(...),
            );
            $properties->property_type_provider->registerClosure(
                $className,
                ModelPropertyHandler::getPropertyType(...),
            );

            // Register pseudo_property_set_types for migration-inferred columns so that
            // property writes are recognized natively by Psalm (fixes sealAllProperties).
            // Uses mixed type since the actual write type depends on casts which may not
            // be fully resolvable at this stage. Read handlers above provide strict types.
            self::registerWriteTypesForColumns($storage, $className);
        }

        // NOTE: write types for accessor/relationship properties (registerWriteTypesForMethods) are
        // registered AFTER warmUp() in afterCodebasePopulated() — its mutator branch now reads the
        // registry's mutators(), which is only populated once warmUp() has run for this model.
    }

    /**
     * For models with custom builders, detect @method static annotations from traits
     * that return Builder<static> (e.g., SoftDeletes::withTrashed). Remove them from
     * the model's pseudo_static_methods so our handler provides the correct custom builder
     * return type, and register method handlers on the custom builder class so builder
     * instance calls also resolve.
     *
     * This is generic: any trait following Laravel's convention of declaring builder
     * methods via @method static returning Builder<static> is handled automatically.
     *
     * @param class-string<Model> $modelClass
     * @param class-string<Builder> $builderClass
     */
    private static function handleTraitBuilderMethods(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $modelClass,
        string $builderClass,
    ): void {
        $traitMethods = self::extractBuilderReturningMethods($storage, $builderClass);
        if ($traitMethods === []) {
            return;
        }

        // Remove from model's pseudo_static_methods so Psalm doesn't resolve them
        // natively with Builder<Post> return type — our handler provides PostBuilder<Post>.
        foreach (\array_keys($traitMethods) as $methodName) {
            unset($storage->pseudo_static_methods[$methodName]);
        }

        CustomBuilderMethodHandler::registerTraitBuilderMethods($modelClass, $traitMethods);

        // Register method handlers on the custom builder class so builder instance
        // calls like Post::query()->withTrashed() also resolve correctly.
        $methods = $codebase->methods;
        $methods->existence_provider->registerClosure(
            $builderClass,
            CustomBuilderMethodHandler::doesTraitMethodExistOnBuilder(...),
        );
        $methods->visibility_provider->registerClosure(
            $builderClass,
            CustomBuilderMethodHandler::isTraitMethodVisibleOnBuilder(...),
        );
        $methods->params_provider->registerClosure(
            $builderClass,
            CustomBuilderMethodHandler::getTraitMethodParamsOnBuilder(...),
        );
        $methods->return_type_provider->registerClosure(
            $builderClass,
            CustomBuilderMethodHandler::getTraitMethodReturnTypeOnBuilder(...),
        );
    }

    /**
     * Extract @method static declarations that return Builder<static> or the model's
     * custom builder class from a model's pseudo_static_methods. These typically
     * originate from traits like SoftDeletes (which register builder macros via
     * global scopes), but may also include model-level @method annotations; this
     * is acceptable as we only act on methods whose return type is a builder.
     *
     * @param class-string<Builder>|null $customBuilderClass
     * @return array<lowercase-string, list<FunctionLikeParameter>>
     * @psalm-mutation-free
     */
    private static function extractBuilderReturningMethods(
        ClassLikeStorage $storage,
        ?string $customBuilderClass = null,
    ): array {
        $builderClassLower = \strtolower(Builder::class);
        $customBuilderClassLower = $customBuilderClass !== null ? \strtolower($customBuilderClass) : null;
        $result = [];

        foreach ($storage->pseudo_static_methods as $methodName => $methodStorage) {
            $returnType = $methodStorage->return_type;
            if ($returnType === null) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                // Laravel traits such as SoftDeletes declare Builder<static>, while apps often
                // override those macros in model PHPDoc with a concrete custom builder return.
                // Keep the generic and named-object checks separate so we do not treat arbitrary
                // generic classes as builder macros, but still catch non-templated custom builders.
                if ($type instanceof TGenericObject && \strtolower($type->value) === $builderClassLower) {
                    $result[$methodName] = $methodStorage->params;
                    break;
                }

                if (
                    $customBuilderClassLower !== null
                    && $type instanceof TNamedObject
                    && \strtolower($type->value) === $customBuilderClassLower
                ) {
                    $result[$methodName] = $methodStorage->params;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Populates pseudo_property_set_types on the model's ClassLikeStorage for each
     * migration-inferred column that doesn't already have a user-defined @property-write.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
     */
    private static function registerWriteTypesForColumns(ClassLikeStorage $storage, string $className): void
    {
        $columns = ModelPropertyHandler::resolveAllColumns($className);
        if ($columns === []) {
            return;
        }

        $mixedType = Type::getMixed();
        // Use Psalm's property index instead of per-column \property_exists() reflection calls.
        // declaring_property_ids includes inherited properties, matching property_exists() semantics.
        $declaredProperties = $storage->declaring_property_ids;

        foreach (\array_keys($columns) as $columnName) {
            $pseudoKey = '$' . $columnName;

            if (self::hasUserDefinedPseudoProperty($storage, $pseudoKey)) {
                continue;
            }

            // Skip native PHP properties (already tracked by Psalm)
            if (isset($declaredProperties[$columnName])) {
                continue;
            }

            $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
        }
    }

    /**
     * Registers pseudo_property_set_types for accessor and relationship properties
     * in a single pass over declaring_method_ids.
     *
     * - Legacy setXxxAttribute mutators: registers mixed write type
     * - New-style Attribute<TGet, TSet>: uses TSet (skips if TSet is `never`)
     * - Relationship methods: registers mixed write type
     */
    private static function registerWriteTypesForMethods(Codebase $codebase, ClassLikeStorage $storage): void
    {
        // Mutator writes (legacy setXxxAttribute + Attribute set:) come from the registry's mutators()
        // map — the production consumer that validates mutators(). Runs first so a mutator key claims
        // its pseudo-property before the relation pass (mirrors the old single-pass guard order);
        // registerWriteTypesForColumns already ran during registration, so column keys still win.
        self::registerMutatorWriteTypes($storage);

        // Relation writes keep their own declared-return-type detection — relations() is a different
        // (own-class, parse-body) contract that intentionally does NOT cover the write set.
        self::registerRelationWriteTypes($codebase, $storage);
    }

    /**
     * Bake mutator write types into `pseudo_property_set_types` from the registry's `mutators()` map.
     * Legacy `setXxxAttribute` → mixed; `Attribute<TGet, TSet>` → TSet (read-only `never` setters are
     * already excluded from `mutators()`). The map is keyed by the separator-collapsed accessor
     * identity, but a write targets the snake_case property the user assigns (`$model->first_name`),
     * so the snake key is re-derived per mutator from its declaring method's cased name — legacy strips
     * `set`…`Attribute`, attribute-style uses the whole method name — exactly the pre-registry
     * derivation. The `hasUserDefinedPseudoProperty` guard keeps a user `@property` (and any
     * already-registered column write) authoritative.
     */
    private static function registerMutatorWriteTypes(ClassLikeStorage $storage): void
    {
        // Only Model subclasses reach warm-up, so the registry entry (if any) is keyed by this FQCN.
        /** @var class-string<Model> $modelFqcn */
        $modelFqcn = $storage->name;
        $metadata = ModelMetadataRegistry::for($modelFqcn);
        if (!$metadata instanceof ModelMetadata) {
            return;
        }

        foreach ($metadata->mutators() as $mutator) {
            $casedName = $mutator->method->cased_name;
            if ($casedName === null) {
                continue;
            }

            if ($mutator instanceof AttributeMutatorInfo) {
                // Attribute-style: the property is the whole method name (`firstName` → `first_name`).
                $propertyName = self::studlyToSnakeCase($casedName);
                $setType = $mutator->setType;
            } else {
                // Legacy `setXxxAttribute` → property `xxx` (strip the `set` prefix + `Attribute` suffix).
                $propertyName = self::studlyToSnakeCase(\substr($casedName, 3, -9));
                $setType = Type::getMixed();
            }

            if ($propertyName === '') {
                continue;
            }

            $pseudoKey = '$' . $propertyName;
            if (!self::hasUserDefinedPseudoProperty($storage, $pseudoKey)) {
                $storage->pseudo_property_set_types[$pseudoKey] = $setType;
            }
        }
    }

    /**
     * Register mixed write types for relationship properties. Detection stays on the DECLARED return
     * type (a Relation subclass) over the full callable method set — a different, broader contract
     * than the registry's own-class parse-based `relations()`, which is therefore not used here. The
     * pseudo key is the relation method name verbatim (`$posts`), not a snake-cased property.
     */
    private static function registerRelationWriteTypes(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $mixedType = Type::getMixed();

        foreach ($storage->declaring_method_ids as $methodIdentifier) {
            // Skip inherited framework methods — only user-defined methods can be relations.
            if (\str_starts_with($methodIdentifier->fq_class_name, 'Illuminate\\')) {
                continue;
            }

            $methodStorage = self::getMethodStorage($codebase, $methodIdentifier);
            if (!$methodStorage instanceof \Psalm\Storage\MethodStorage) {
                continue;
            }

            $casedName = $methodStorage->cased_name;
            if ($casedName === null) {
                continue;
            }

            $returnType = $methodStorage->return_type;
            if (!$returnType instanceof Union) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                if ($type instanceof TNamedObject && \is_a($type->value, Relation::class, true)) {
                    $pseudoKey = '$' . $casedName;
                    if (!self::hasUserDefinedPseudoProperty($storage, $pseudoKey)) {
                        $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
                    }

                    break;
                }
            }
        }
    }

    /**
     * Check if the user has already defined a pseudo-property (via @property, @property-read,
     * or @property-write) for this key. If so, the plugin should not override it.
     *
     * @psalm-mutation-free
     */
    private static function hasUserDefinedPseudoProperty(ClassLikeStorage $storage, string $pseudoKey): bool
    {
        return (
            isset($storage->pseudo_property_set_types[$pseudoKey])
            || isset($storage->pseudo_property_get_types[$pseudoKey])
        );
    }

    /** @psalm-mutation-free */
    private static function getMethodStorage(Codebase $codebase, MethodIdentifier $methodIdentifier): ?MethodStorage
    {
        try {
            return $codebase->methods->getStorage($methodIdentifier);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Convert StudlyCase/camelCase to snake_case.
     *
     * 'PublishedAt' → 'published_at', 'firstName' → 'first_name'
     *
     * @psalm-pure
     */
    private static function studlyToSnakeCase(string $value): string
    {
        return \ltrim(\strtolower(\preg_replace('/[A-Z]/', '_$0', $value) ?? $value), '_');
    }
}
