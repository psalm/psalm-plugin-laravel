<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
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
 * classes, then register handlers for every concrete Model subclass found.
 *
 * Registers per-model:
 * - Method existence, visibility, params, and return types ({@see ModelMethodHandler})
 * - Property existence, visibility, and types (relationships, accessors, columns)
 *
 * @internal
 */
final class ModelRegistrationHandler implements AfterCodebasePopulatedInterface
{
    private static bool $useMigrations = false;

    /** @psalm-external-mutation-free */
    public static function enableMigrations(): void
    {
        self::$useMigrations = true;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $modelFqcn = \strtolower(Model::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract) {
                continue;
            }

            if (!isset($storage->parent_classes[$modelFqcn])) {
                continue;
            }

            // Force-load the class via Composer's autoloader so that runtime
            // reflection works in property handlers (e.g. getTable(), getCasts())
            try {
                if (!\class_exists($storage->name, true)) {
                    $codebase->progress->warning("Laravel plugin: skipping model '{$storage->name}': class could not be loaded by autoloader");
                    continue;
                }
            } catch (\Error|\Exception $error) {
                $codebase->progress->warning("Laravel plugin: skipping model '{$storage->name}': {$error->getMessage()}");
                continue;
            }

            self::registerHandlersForModel($codebase, $storage);
        }
    }

    private static function registerHandlersForModel(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $className = $storage->name;
        $properties = $codebase->properties;
        $methods = $codebase->methods;

        // Detect custom builder class via attribute, method override, or $builder property.
        // Class is already loaded by autoloader above.
        /** @var class-string<Model> $className — verified by parent_classes check in caller */
        $customBuilder = self::detectCustomBuilder($codebase, $className);

        // For models with custom builders: handle @method static annotations from traits
        // (e.g., SoftDeletes::withTrashed) that return Builder<static>. These are builder
        // macros at runtime — remap them to return the custom builder type.
        if ($customBuilder !== null) {
            self::handleTraitBuilderMethods($codebase, $storage, $className, $customBuilder);

            // Register scope handlers for the custom builder class so that builder
            // instance calls like Post::query()->featured() resolve correctly.
            // BuilderScopeHandler only covers base Builder — custom subclasses need
            // explicit registration. See https://github.com/psalm/psalm-plugin-laravel/issues/630
            $methods->existence_provider->registerClosure(
                $customBuilder,
                ModelMethodHandler::doesScopeMethodExistOnBuilder(...),
            );
            $methods->visibility_provider->registerClosure(
                $customBuilder,
                ModelMethodHandler::isScopeMethodVisibleOnBuilder(...),
            );
            $methods->params_provider->registerClosure(
                $customBuilder,
                ModelMethodHandler::getScopeMethodParamsOnBuilder(...),
            );
            $methods->return_type_provider->registerClosure(
                $customBuilder,
                ModelMethodHandler::getScopeMethodReturnTypeOnBuilder(...),
            );
        }

        // Detect custom collection class via #[CollectedBy] attribute or newCollection() override.
        // Class is already loaded by autoloader above, so runtime reflection works.
        self::detectCustomCollection($codebase, $className);

        // Method existence, visibility, and return types for static __callStatic forwarding.
        // Registered per-model because Psalm's provider lookup uses exact class names —
        // a handler for Model::class is not consulted for App\Models\User.
        $methods->existence_provider->registerClosure(
            $className,
            ModelMethodHandler::doesMethodExist(...),
        );
        $methods->visibility_provider->registerClosure(
            $className,
            ModelMethodHandler::isMethodVisible(...),
        );
        $methods->params_provider->registerClosure(
            $className,
            ModelMethodHandler::getMethodParams(...),
        );
        $methods->return_type_provider->registerClosure(
            $className,
            ModelMethodHandler::getReturnTypeForForwardedMethod(...),
        );
        // Custom collection: narrows Model::all() return type for models using
        // #[CollectedBy] or overriding newCollection() with a concrete subclass.
        $methods->return_type_provider->registerClosure(
            $className,
            CustomCollectionHandler::getModelMethodReturnType(...),
        );

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

        // 2. Factory property ($model::factory())
        $properties->property_type_provider->registerClosure(
            $className,
            ModelFactoryTypeProvider::getPropertyType(...),
        );

        // 3. Accessor properties (e.g. $user->full_name via attribute accessor)
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

        // 4. Column properties from migrations (e.g. $user->email)
        if (self::$useMigrations) {
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

        // Register write types for accessor and relationship properties
        self::registerWriteTypesForMethods($codebase, $storage);
    }

    /**
     * Detect a custom Eloquent builder for a model and register it.
     *
     * Matches Laravel's own resolution priority in Model::newEloquentBuilder():
     * 1. newEloquentBuilder() override — if the model overrides this method, it bypasses
     *    the attribute and property checks entirely (Laravel calls the override directly)
     * 2. #[UseEloquentBuilder] attribute — checked first inside the base newEloquentBuilder()
     * 3. protected static string $builder property — fallback in the base newEloquentBuilder()
     *
     * @param class-string<Model> $className
     * @return class-string<Builder>|null The custom builder class, or null if using base Builder.
     */
    private static function detectCustomBuilder(Codebase $codebase, string $className): ?string
    {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $reflectionException) {
            $codebase->progress->debug(
                "Laravel plugin: could not reflect model '{$className}' for custom builder detection: {$reflectionException->getMessage()}\n",
            );

            return null;
        }

        // 1. newEloquentBuilder() override — bypasses attribute and property when present.
        $builderClass = self::resolveBuilderFromMethodOverride($reflection);

        // 2. #[UseEloquentBuilder] attribute — checked first in the base newEloquentBuilder().
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromAttribute($reflection, $codebase);
        }

        // 3. Fall back to static $builder property.
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromStaticProperty($reflection);
        }

        if ($builderClass === null) {
            return null;
        }

        // is_subclass_of() may trigger autoloading which can throw for broken classes.
        try {
            $isValid = \is_subclass_of($builderClass, Builder::class, true);
        } catch (\Error|\Exception $error) {
            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' builder '{$builderClass}' failed autoloading: {$error->getMessage()}\n",
            );

            return null;
        }

        if ($isValid) {
            /** @var class-string<Builder> $builderClass */
            ModelMethodHandler::registerCustomBuilder($className, $builderClass);

            return $builderClass;
        }

        $codebase->progress->debug(
            "Laravel plugin: model '{$className}' declares custom builder '{$builderClass}' "
            . "but it does not extend " . Builder::class . " — ignoring\n",
        );

        return null;
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
        $traitMethods = self::extractBuilderReturningMethods($storage);
        if ($traitMethods === []) {
            return;
        }

        // Remove from model's pseudo_static_methods so Psalm doesn't resolve them
        // natively with Builder<Post> return type — our handler provides PostBuilder<Post>.
        foreach (\array_keys($traitMethods) as $methodName) {
            unset($storage->pseudo_static_methods[$methodName]);
        }

        ModelMethodHandler::registerTraitBuilderMethods($modelClass, $traitMethods);

        // Register method handlers on the custom builder class so builder instance
        // calls like Post::query()->withTrashed() also resolve correctly.
        $methods = $codebase->methods;
        $methods->existence_provider->registerClosure(
            $builderClass,
            ModelMethodHandler::doesTraitMethodExistOnBuilder(...),
        );
        $methods->visibility_provider->registerClosure(
            $builderClass,
            ModelMethodHandler::isTraitMethodVisibleOnBuilder(...),
        );
        $methods->params_provider->registerClosure(
            $builderClass,
            ModelMethodHandler::getTraitMethodParamsOnBuilder(...),
        );
        $methods->return_type_provider->registerClosure(
            $builderClass,
            ModelMethodHandler::getTraitMethodReturnTypeOnBuilder(...),
        );
    }

    /**
     * Extract @method static declarations that return Builder<static> from
     * a model's pseudo_static_methods. These typically originate from traits
     * like SoftDeletes (which register builder macros via global scopes), but
     * may also include model-level @method annotations; this is acceptable as
     * we only act on methods whose return type is a generic Builder.
     *
     * @return array<lowercase-string, list<FunctionLikeParameter>>
     * @psalm-mutation-free
     */
    private static function extractBuilderReturningMethods(ClassLikeStorage $storage): array
    {
        $builderClassLower = \strtolower(Builder::class);
        $result = [];

        foreach ($storage->pseudo_static_methods as $methodName => $methodStorage) {
            $returnType = $methodStorage->return_type;
            if ($returnType === null) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                if (
                    $type instanceof TGenericObject
                    && \strtolower($type->value) === $builderClassLower
                ) {
                    $result[$methodName] = $methodStorage->params;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Resolve custom builder from #[UseEloquentBuilder] attribute.
     *
     * @return class-string|null
     */
    private static function resolveBuilderFromAttribute(\ReflectionClass $reflection, Codebase $codebase): ?string
    {
        $attributes = $reflection->getAttributes(UseEloquentBuilder::class);
        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance()->builderClass;
        } catch (\Error $error) {
            $codebase->progress->debug(
                "Laravel plugin: #[UseEloquentBuilder] on '{$reflection->getName()}' failed to instantiate: {$error->getMessage()}\n",
            );

            return null;
        }
    }

    /**
     * Resolve custom builder from a newEloquentBuilder() override with a native return type.
     *
     * Detects any override whose declaring class is not Illuminate\Database\Eloquent\Model
     * (including overrides in an intermediate base model) with a PHP native return type.
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveBuilderFromMethodOverride(\ReflectionClass $reflection): ?string
    {
        try {
            $method = $reflection->getMethod('newEloquentBuilder');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides declared on the model, not the base Model method.
        if ($method->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        return $returnType->getName();
    }

    /**
     * Resolve custom builder from a static $builder property override (all Laravel versions).
     *
     * Detects when a model overrides the protected static string $builder property
     * with a custom builder class name.
     *
     * @return class-string|null
     */
    private static function resolveBuilderFromStaticProperty(\ReflectionClass $reflection): ?string
    {
        try {
            $property = $reflection->getProperty('builder');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides, not the base Model::$builder = Builder::class.
        if ($property->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        /** @psalm-var class-string|null $value */
        $value = $property->getValue();

        return $value;
    }

    /**
     * Detect a custom Eloquent collection for a model and register it.
     *
     * Matches Laravel's own resolution priority in HasCollection::newCollection():
     * 1. newCollection() override — if the model overrides this method, it bypasses
     *    the attribute and property checks entirely (Laravel calls the override directly)
     * 2. #[CollectedBy] attribute — checked first inside the base newCollection()
     * 3. protected static string $collectionClass property — fallback in the base newCollection()
     *
     * Uses runtime reflection (consistent with custom builder detection) since the model
     * class is already loaded by the autoloader in the caller.
     *
     * @param class-string<Model> $className
     */
    private static function detectCustomCollection(Codebase $codebase, string $className): void
    {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $reflectionException) {
            $codebase->progress->debug(
                "Laravel plugin: could not reflect model '{$className}' for custom collection detection: {$reflectionException->getMessage()}\n",
            );

            return;
        }

        // 1. newCollection() override — bypasses attribute and property when present.
        $collectionClass = self::resolveCollectionFromMethodOverride($reflection);

        // 2. #[CollectedBy] attribute — checked first in the base newCollection().
        if ($collectionClass === null) {
            $collectionClass = self::resolveCollectionFromAttribute($reflection, $codebase);
        }

        // 3. Fall back to static $collectionClass property.
        if ($collectionClass === null) {
            $collectionClass = self::resolveCollectionFromStaticProperty($reflection);
        }

        if ($collectionClass === null) {
            return;
        }

        // Validate that the class is a Collection subclass.
        // is_subclass_of() may trigger autoloading which can throw for broken classes.
        try {
            $isValid = \is_subclass_of($collectionClass, EloquentCollection::class, true);
        } catch (\Error|\Exception $error) {
            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' collection '{$collectionClass}' failed autoloading: {$error->getMessage()}\n",
            );

            return;
        }

        if ($isValid) {
            /** @var class-string<EloquentCollection> $collectionClass */
            CustomCollectionHandler::registerCustomCollection($className, $collectionClass);

            return;
        }

        $codebase->progress->debug(
            "Laravel plugin: model '{$className}' declares custom collection '{$collectionClass}' "
            . "but it does not extend " . EloquentCollection::class . " — ignoring\n",
        );
    }

    /**
     * Resolve custom collection from #[CollectedBy] attribute.
     *
     * Walks up the parent class chain for grandchild models, matching Laravel's
     * HasCollection::resolveCollectionFromAttribute() behavior: if a base model
     * declares #[CollectedBy], child models inherit the custom collection.
     *
     * @return class-string|null
     */
    private static function resolveCollectionFromAttribute(\ReflectionClass $reflection, Codebase $codebase): ?string
    {
        $attributes = $reflection->getAttributes(CollectedBy::class);
        if ($attributes !== []) {
            try {
                /** @psalm-var class-string */
                return $attributes[0]->newInstance()->collectionClass;
            } catch (\Error $error) {
                $codebase->progress->debug(
                    "Laravel plugin: #[CollectedBy] on '{$reflection->getName()}' failed to instantiate: {$error->getMessage()}\n",
                );

                return null;
            }
        }

        // Walk up to parent model (grandchild inheritance), matching Laravel's behavior.
        // A model whose direct parent is Model itself has no intermediate base to inherit from.
        $parentClass = $reflection->getParentClass();
        if (
            $parentClass !== false
            && $parentClass->getName() !== Model::class
            && $parentClass->isSubclassOf(Model::class)
        ) {
            return self::resolveCollectionFromAttribute($parentClass, $codebase);
        }

        return null;
    }

    /**
     * Resolve custom collection from a newCollection() override with a native return type.
     *
     * Detects any override whose declaring class is not Illuminate\Database\Eloquent\Model
     * (including overrides in an intermediate base model) with a PHP native return type
     * that is a concrete class (not EloquentCollection itself).
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveCollectionFromMethodOverride(\ReflectionClass $reflection): ?string
    {
        try {
            $method = $reflection->getMethod('newCollection');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides declared on the model, not the base Model method.
        if ($method->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $typeName = $returnType->getName();

        // Skip if it just returns the base EloquentCollection — not a custom collection.
        if ($typeName === EloquentCollection::class) {
            return null;
        }

        return $typeName;
    }

    /**
     * Resolve custom collection from a static $collectionClass property override (all Laravel versions).
     *
     * Detects when a model overrides the protected static string $collectionClass property
     * with a custom collection class name.
     *
     * @return class-string|null
     */
    private static function resolveCollectionFromStaticProperty(\ReflectionClass $reflection): ?string
    {
        try {
            $property = $reflection->getProperty('collectionClass');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides, not the base Model::$collectionClass = Collection::class.
        if ($property->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        /** @psalm-var class-string|null $value */
        $value = $property->getValue();

        return $value;
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
        $mixedType = Type::getMixed();

        foreach ($storage->declaring_method_ids as $methodName => $methodIdentifier) {
            // Skip inherited framework methods — only user-defined methods can be accessors/relations
            if (\str_starts_with($methodIdentifier->fq_class_name, 'Illuminate\\')) {
                continue;
            }

            // Fetch method storage once — used for both cased_name and return_type.
            // This avoids the overhead of getMethodReturnType() (alias resolution, declaring/appearing
            // method lookups, template substitution) and the redundant getStorage() call in getCasedMethodName().
            $methodStorage = self::getMethodStorage($codebase, $methodIdentifier);
            if (!$methodStorage instanceof \Psalm\Storage\MethodStorage) {
                continue;
            }

            $casedName = $methodStorage->cased_name;
            if ($casedName === null) {
                continue;
            }

            // Legacy mutator: setXxxAttribute → property xxx
            if (\str_starts_with($methodName, 'set') && \str_ends_with($methodName, 'attribute') && $methodName !== 'setattribute') {
                $propertyName = self::studlyToSnakeCase(\substr($casedName, 3, -9));
                if ($propertyName === '') {
                    continue;
                }

                $pseudoKey = '$' . $propertyName;
                if (!self::hasUserDefinedPseudoProperty($storage, $pseudoKey)) {
                    $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
                }

                continue;
            }

            // Check return type for Attribute accessors and Relation methods
            $returnType = $methodStorage->return_type;
            if (!$returnType instanceof Union) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                if (!$type instanceof TNamedObject) {
                    continue;
                }

                // New-style Attribute accessor
                if (\is_a($type->value, Attribute::class, true)) {
                    $pseudoKey = '$' . self::studlyToSnakeCase($casedName);
                    if (self::hasUserDefinedPseudoProperty($storage, $pseudoKey)) {
                        break;
                    }

                    $setType = $type instanceof TGenericObject ? ($type->type_params[1] ?? null) : null;
                    if ($setType instanceof Union && $setType->isNever()) {
                        break;
                    }

                    $storage->pseudo_property_set_types[$pseudoKey] = $setType instanceof Union ? $setType : $mixedType;
                    break;
                }

                // Relationship method
                if (\is_a($type->value, Relation::class, true)) {
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
        return isset($storage->pseudo_property_set_types[$pseudoKey])
            || isset($storage->pseudo_property_get_types[$pseudoKey]);
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
