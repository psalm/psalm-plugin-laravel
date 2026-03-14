<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

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

    /** @psalm-suppress MissingPureAnnotation mutates static flag intentionally */
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

            self::registerHandlersForModel($codebase, $storage->name);
        }
    }

    /** @psalm-suppress MissingPureAnnotation mutates external codebase property providers */
    private static function registerHandlersForModel(Codebase $codebase, string $className): void
    {
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
        }
    }
}
