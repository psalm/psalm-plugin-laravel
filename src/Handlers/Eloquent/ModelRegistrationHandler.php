<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
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
                    $codebase->progress->debug("Laravel plugin: skipping model '{$storage->name}': class could not be loaded by autoloader\n");
                    continue;
                }
            } catch (\Error $error) {
                $codebase->progress->debug("Laravel plugin: skipping model '{$storage->name}': {$error->getMessage()}\n", );
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
        self::detectCustomBuilder($codebase, $className);

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
     * Checks three patterns (in priority order):
     * 1. #[UseEloquentBuilder(CustomBuilder::class)] attribute (Laravel 12+)
     * 2. newEloquentBuilder() override with a native return type (any Laravel version)
     * 3. protected static string $builder property override (all Laravel versions)
     *
     * @param class-string<Model> $className
     */
    private static function detectCustomBuilder(Codebase $codebase, string $className): void
    {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $reflectionException) {
            $codebase->progress->debug(
                "Laravel plugin: could not reflect model '{$className}' for custom builder detection: {$reflectionException->getMessage()}\n",
            );

            return;
        }

        // 1. #[UseEloquentBuilder] attribute (Laravel 12+) takes priority.
        $builderClass = self::resolveBuilderFromAttribute($reflection, $codebase);

        // 2. Fall back to newEloquentBuilder() return type override.
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromMethodOverride($reflection);
        }

        // 3. Fall back to static $builder property override (all Laravel versions).
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromStaticProperty($reflection);
        }

        if ($builderClass === null) {
            return;
        }

        // is_subclass_of() may trigger autoloading which can throw \Error for broken classes.
        try {
            $isValid = \is_subclass_of($builderClass, Builder::class, true);
        } catch (\Error $error) {
            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' builder '{$builderClass}' failed autoloading: {$error->getMessage()}\n",
            );

            return;
        }

        if ($isValid) {
            /** @var class-string<Builder> $builderClass */
            ModelMethodHandler::registerCustomBuilder($className, $builderClass);
        } else {
            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' declares custom builder '{$builderClass}' "
                . "but it does not extend " . Builder::class . " — ignoring\n",
            );
        }
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

        /** @var mixed $value */
        $value = $property->getValue();

        /** @var class-string|null */
        return \is_string($value) ? $value : null;
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
