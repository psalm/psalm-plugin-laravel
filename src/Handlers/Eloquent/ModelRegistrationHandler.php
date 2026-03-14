<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Discovers Eloquent model classes from Psalm's scanned codebase and registers
 * property handlers for each discovered model.
 *
 * This replaces directory-based model scanning: instead of pre-scanning directories
 * for model files, we wait until Psalm has populated its codebase with all project
 * classes, then register property handlers for every concrete Model subclass found.
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
        self::registerWriteTypesForAccessors($codebase, $storage);
        self::registerWriteTypesForRelationships($codebase, $storage);
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

        foreach (\array_keys($columns) as $columnName) {
            $pseudoKey = '$' . $columnName;

            // Skip if user already defined @property-write or @property for this column
            if (isset($storage->pseudo_property_set_types[$pseudoKey])) {
                continue;
            }

            // Skip native PHP properties
            if (\property_exists($className, $columnName)) {
                continue;
            }

            $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
        }
    }

    /**
     * Registers pseudo_property_set_types for accessor properties.
     *
     * - New-style Attribute<TGet, TSet>: uses TSet as write type (skips if TSet is `never`)
     * - Legacy setXxxAttribute mutators: uses mixed
     */
    private static function registerWriteTypesForAccessors(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $mixedType = Type::getMixed();

        // declaring_method_ids keys are lowercase
        foreach (\array_keys($storage->declaring_method_ids) as $methodName) {
            // Legacy mutator: setxxxattribute → property xxx
            if (\str_starts_with($methodName, 'set') && \str_ends_with($methodName, 'attribute') && $methodName !== 'setattribute') {
                $propertyName = \substr($methodName, 3, -9);
                $pseudoKey = '$' . $propertyName;
                if ($propertyName !== '' && !isset($storage->pseudo_property_set_types[$pseudoKey])) {
                    $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
                }

                continue;
            }

            // New-style Attribute accessor: camelCase method returning Attribute<TGet, TSet>
            $selfClass = $storage->name;
            $methodId = $selfClass . '::' . $methodName;
            $returnType = $codebase->getMethodReturnType($methodId, $selfClass);
            if (!$returnType instanceof Type\Union) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                if (!$type instanceof TGenericObject || !\is_a($type->value, Attribute::class, true)) {
                    continue;
                }

                // TSet is the second template parameter
                $setType = $type->type_params[1] ?? null;
                if (!$setType instanceof Type\Union || $setType->isNever()) {
                    break;
                }

                // camelCase method → snake_case property (keys are already lowercase)
                $pseudoKey = '$' . $methodName;
                if (!isset($storage->pseudo_property_set_types[$pseudoKey])) {
                    $storage->pseudo_property_set_types[$pseudoKey] = $setType;
                }

                break;
            }
        }
    }

    /**
     * Registers pseudo_property_set_types for relationship properties.
     *
     * Eloquent allows `$model->relation = $value` to cache in the relations array.
     */
    private static function registerWriteTypesForRelationships(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $mixedType = Type::getMixed();

        foreach (\array_keys($storage->declaring_method_ids) as $methodName) {
            $pseudoKey = '$' . $methodName;

            if (isset($storage->pseudo_property_set_types[$pseudoKey])) {
                continue;
            }

            $selfClass = $storage->name;
            $methodId = $selfClass . '::' . $methodName;
            $returnType = $codebase->getMethodReturnType($methodId, $selfClass);
            if (!$returnType instanceof Type\Union) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $type) {
                if ($type instanceof TGenericObject && \is_a($type->value, Relation::class, true)) {
                    $storage->pseudo_property_set_types[$pseudoKey] = $mixedType;
                    break;
                }
            }
        }
    }
}
