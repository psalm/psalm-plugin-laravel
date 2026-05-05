<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Util\AnonymousClassNameDetector;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Discovers Eloquent Factory subclasses and registers {@see FactoryMagicMethodHandler}
 * hooks for each one whose target Model can be resolved.
 *
 * Resolution priority (matches Laravel's own behavior in Factory::modelName() where
 * possible, falling back to static analysis when runtime instantiation is not viable):
 *
 *  1. Reflection: instantiate the factory and call modelName(). Covers all three
 *     runtime resolution paths in one shot — `#[UseModel]` attribute (highest), then
 *     `protected $model`, then the naming-convention resolver
 *     (Database\Factories\PostFactory → App\Models\Post).
 *
 *  2. Static fallback: read the @extends Factory<TModel> template binding from
 *     {@see ClassLikeStorage::$template_extended_params}. This covers factories that
 *     are not autoloadable in the analysis environment (e.g. inline declarations in
 *     PHPT type-tests) but have an explicit generic extends.
 *
 * @internal
 */
final class FactoryRegistrationHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $factoryFqcnLower = \strtolower(Factory::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            // Skip abstract bases — they don't bind a concrete model and are
            // typically intermediate parents in user-land factory hierarchies.
            if ($storage->abstract) {
                continue;
            }

            if (!isset($storage->parent_classes[$factoryFqcnLower])) {
                continue;
            }

            // Anonymous Factory subclasses (e.g. `new class extends Factory {}`) get a
            // synthetic FQCN that no autoloader can resolve. Skip them before any
            // class_exists() probe to avoid misleading warnings.
            if (
                $storage->stmt_location !== null
                && AnonymousClassNameDetector::isSynthetic($storage->name, $storage->stmt_location->file_path)
            ) {
                continue;
            }

            // Caller verified parent_classes contains Factory, so the name is a
            // class-string<Factory>. Narrow it for downstream Reflection / closure
            // registration which both require the tighter type.
            /** @var class-string<Factory> $factoryClass */
            $factoryClass = $storage->name;

            $modelClass = self::resolveTargetModel($codebase, $factoryClass, $storage);
            if ($modelClass === null) {
                continue;
            }

            FactoryMagicMethodHandler::registerFactoryToModelMapping($factoryClass, $modelClass);

            $methods = $codebase->methods;
            $methods->existence_provider->registerClosure(
                $factoryClass,
                FactoryMagicMethodHandler::doesMethodExist(...),
            );
            $methods->visibility_provider->registerClosure(
                $factoryClass,
                FactoryMagicMethodHandler::isMethodVisible(...),
            );
            $methods->params_provider->registerClosure(
                $factoryClass,
                FactoryMagicMethodHandler::getMethodParams(...),
            );
            $methods->return_type_provider->registerClosure(
                $factoryClass,
                FactoryMagicMethodHandler::getMethodReturnType(...),
            );
        }
    }

    /**
     * Resolve the Model FQCN for a Factory subclass, trying Reflection-based runtime
     * resolution first and falling back to static template extraction.
     *
     * @param class-string<Factory> $factoryClass
     * @return class-string<Model>|null
     */
    private static function resolveTargetModel(Codebase $codebase, string $factoryClass, ClassLikeStorage $storage): ?string
    {
        $modelClass = self::resolveViaReflection($codebase, $factoryClass);
        if ($modelClass !== null) {
            return $modelClass;
        }

        return self::resolveViaTemplateBinding($codebase, $storage);
    }

    /**
     * Instantiate the factory and call modelName(). Returns null when the class is
     * not autoloadable, instantiation throws, or the resolved string is not a
     * loaded Model subclass.
     *
     * Uses ReflectionClass rather than `new $factoryClass()` so that an autoload
     * failure surfaces as a typed ReflectionException we can ignore quietly,
     * instead of a fatal Error.
     *
     * @param class-string<Factory> $factoryClass
     * @return class-string<Model>|null
     */
    private static function resolveViaReflection(Codebase $codebase, string $factoryClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($factoryClass);
            $factory = $reflection->newInstance();
            $modelClass = $factory->modelName();
        } catch (\Error|\Exception $error) {
            // Surface as a warning (mirrors ModelRegistrationHandler's autoload
            // failure handling) — the user needs to know why their factory's
            // magic methods aren't recognized. \Error|\Exception narrowed from
            // \Throwable so genuinely unexpected exception types propagate.
            $codebase->progress->warning(
                "Laravel plugin: skipping factory '{$factoryClass}': could not call modelName(): {$error->getMessage()}",
            );
            return null;
        }

        // Verify the resolved string is a real Model subclass. The naming-convention
        // resolver in Laravel's modelName() always returns a string even when no
        // matching class exists, so this guard prevents registering handlers with a
        // dangling target. is_subclass_of triggers autoloading which can throw on
        // broken classes — fall through and warn in that case.
        try {
            if (!\is_subclass_of($modelClass, Model::class, true)) {
                return null;
            }
        } catch (\Error|\Exception $error) {
            $codebase->progress->warning(
                "Laravel plugin: factory '{$factoryClass}' resolved model '{$modelClass}' that failed validation: {$error->getMessage()}",
            );
            return null;
        }

        return $modelClass;
    }

    /**
     * Fallback: read TModel from `@extends Factory<TModel>`.
     *
     * Psalm populates {@see ClassLikeStorage::$template_extended_params} during
     * scanning. The map is keyed by the parent FQCN (original case); the value maps
     * template-parameter names to their Union bindings. We accept any single
     * named-object param — Factory's first template is TModel.
     *
     * Validation runs against Psalm's classlike storage rather than runtime
     * reflection, since this fallback exists precisely for cases where the model
     * is not autoloadable (e.g. inline declarations in PHPT type-tests).
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    private static function resolveViaTemplateBinding(Codebase $codebase, ClassLikeStorage $storage): ?string
    {
        if ($storage->template_extended_params === null) {
            return null;
        }

        $factoryFqcnLower = \strtolower(Factory::class);

        foreach ($storage->template_extended_params as $extendedClass => $params) {
            if (\strtolower($extendedClass) !== $factoryFqcnLower) {
                continue;
            }

            // Psalm always keys $params by the declared template-parameter name
            // (Populator.php walks `array_keys($parent_storage->template_types)`),
            // and Factory's docblock declares `@template TModel`. Read 'TModel'
            // first; fall back to the first positional value if Laravel ever
            // renames the template — defensive but expected to be dead code.
            $tModel = $params['TModel'] ?? null;
            if (!$tModel instanceof Union) {
                $firstKey = \array_key_first($params);
                $tModel = $firstKey !== null ? $params[$firstKey] : null;
            }

            if (!$tModel instanceof Union) {
                return null;
            }

            foreach ($tModel->getAtomicTypes() as $atomic) {
                if (!$atomic instanceof TNamedObject) {
                    continue;
                }

                if (!self::isModelInCodebase($codebase, $atomic->value)) {
                    return null;
                }

                /** @var class-string<Model> $resolved */
                $resolved = $atomic->value;
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Check via Psalm's classlike storage whether $className is a Model subclass.
     *
     * Used by the template-binding fallback path because the model is typically
     * not autoloadable when the factory itself isn't (e.g. inline classes). The
     * check uses the case-insensitive `parent_classes` map populated during scan.
     *
     * @psalm-mutation-free
     */
    private static function isModelInCodebase(Codebase $codebase, string $className): bool
    {
        try {
            $modelStorage = $codebase->classlike_storage_provider->get($className);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $className === Model::class
            || isset($modelStorage->parent_classes[\strtolower(Model::class)]);
    }
}
