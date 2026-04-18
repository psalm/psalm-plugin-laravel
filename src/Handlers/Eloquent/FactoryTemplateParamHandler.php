<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Auto-resolves the `TModel` template parameter on user-defined classes that
 * extend `Illuminate\Database\Eloquent\Factories\Factory` without declaring
 * `@extends Factory<TModel>` themselves.
 *
 * Conservative by design: only resolves when the `$model` property is declared
 * with a concrete `class-string<X>` value where `X` is a known Model subclass.
 * Never guesses — unresolved factories remain untyped, and `SuppressHandler`
 * silences the leftover `MissingTemplateParam` for them.
 *
 * Laravel's own `Factory::modelName()` offers richer resolution (static
 * `$modelNameResolvers` map, the pluggable `$modelNameResolver` callable, and a
 * naming-convention fallback that appends `App\Models\` / app namespace). We
 * intentionally skip those because they can silently bind the wrong model when
 * an app uses a non-standard namespace or a custom resolver. Users whose
 * factories aren't resolved by the `$model` property can always opt in with an
 * explicit `@extends Factory<ConcreteModel>` annotation.
 *
 * @internal
 */
final class FactoryTemplateParamHandler implements AfterCodebasePopulatedInterface
{
    private const FACTORY_CLASS = Factory::class;

    private const MODEL_CLASS = Model::class;

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $factoryFqcnLower = \strtolower(self::FACTORY_CLASS);

        foreach ($codebase->classlike_storage_provider::getAll() as $classStorage) {
            if (!$classStorage->user_defined) {
                continue;
            }

            if ($classStorage->is_interface) {
                continue;
            }

            if (!isset($classStorage->parent_classes[$factoryFqcnLower])) {
                continue;
            }

            // Respect explicit `@extends Factory<X>` — don't overwrite user intent.
            // Psalm's populator always seeds `template_extended_params[Factory]` with
            // the unbound default `TModel`, so that field is not a reliable signal.
            // `template_extended_offsets[Factory]` is set only when the user wrote an
            // explicit `@extends` annotation, so it is the correct discriminator.
            if (isset($classStorage->template_extended_offsets[self::FACTORY_CLASS])) {
                continue;
            }

            $modelClass = self::resolveModel($classStorage, $codebase);
            if ($modelClass === null) {
                continue;
            }

            $classStorage->template_extended_params ??= [];
            $classStorage->template_extended_params[self::FACTORY_CLASS] = [
                'TModel' => new Union([new TNamedObject($modelClass)]),
            ];
        }
    }

    /**
     * Returns the FQCN of the Model this factory targets, or null if it can't
     * be determined with certainty.
     *
     * @psalm-mutation-free
     */
    private static function resolveModel(ClassLikeStorage $classStorage, Codebase $codebase): ?string
    {
        $modelProp = $classStorage->properties['model'] ?? null;
        if ($modelProp === null) {
            return null;
        }

        // Prefer suggested_type (inferred from `$model = X::class` default) over
        // type (the declared @var class-string<TModel> from the base Factory,
        // which is parametric and not resolvable on its own).
        $candidate = self::extractClassName($modelProp->suggested_type)
            ?? self::extractClassName($modelProp->type);

        if ($candidate === null) {
            return null;
        }

        return self::isModelSubclass($candidate, $codebase) ? $candidate : null;
    }

    /**
     * Extracts a single concrete class name from a Union, or null if the union
     * is missing, ambiguous, or doesn't point to a specific class.
     *
     * @psalm-mutation-free
     */
    private static function extractClassName(?Union $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $atomics = $type->getAtomicTypes();
        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = \reset($atomics);

        if ($atomic instanceof TLiteralClassString) {
            return $atomic->value;
        }

        if ($atomic instanceof TClassString && $atomic->as_type instanceof TNamedObject) {
            return $atomic->as_type->value;
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function isModelSubclass(string $className, Codebase $codebase): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return isset($storage->parent_classes[\strtolower(self::MODEL_CLASS)]);
    }
}
