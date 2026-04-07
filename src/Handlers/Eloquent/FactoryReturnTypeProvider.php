<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type;

/**
 * Prevents TooManyTemplateParams on Factory subclasses that bind the parent
 * template via @extends but declare no template parameters of their own.
 *
 * When a user-space base factory overrides new() with `@return static<TModel>`,
 * Psalm resolves `static<TModel>` to e.g. `UserFactory<User>`. But UserFactory
 * has 0 own template params (it binds TModel via @extends), so Psalm emits
 * TooManyTemplateParams. This handler strips excess template args from the
 * return type after method call analysis.
 *
 * Uses the same template-aware guard as {@see ModelMethodHandler::builderType()}.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/677
 */
final class FactoryReturnTypeProvider implements AfterMethodCallAnalysisInterface
{
    /** Pre-lowered for hot-path comparisons — this handler fires on every method call. */
    private const FACTORY_CLASS_LOWER = 'illuminate\database\eloquent\factories\factory';

    /** @psalm-external-mutation-free */
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $returnType = $event->getReturnTypeCandidate();
        if ($returnType === null) {
            return;
        }

        // Quick-reject: if no TGenericObject atomics, nothing to fix.
        // This avoids string ops and storage lookups for ~95% of method calls.
        $hasGeneric = false;
        foreach ($returnType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Type\Atomic\TGenericObject) {
                $hasGeneric = true;
                break;
            }
        }

        if (!$hasGeneric) {
            return;
        }

        // Only process calls on Factory subclasses.
        $methodId = $event->getMethodId();
        $className = \explode('::', $methodId)[0] ?? '';
        $classNameLower = \strtolower($className);
        if ($classNameLower === '' || $classNameLower === self::FACTORY_CLASS_LOWER) {
            return;
        }

        $codebase = $event->getCodebase();
        try {
            $calledClassStorage = $codebase->classlike_storage_provider->get($classNameLower);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (!isset($calledClassStorage->parent_classes[self::FACTORY_CLASS_LOWER])) {
            return;
        }

        // Walk the return type and replace TGenericObject with TNamedObject
        // for Factory subclasses that have 0 own template params.
        $changed = false;
        $newAtomics = [];
        foreach ($returnType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof Type\Atomic\TGenericObject) {
                $atomicClassLower = \strtolower($atomic->value);

                // Reuse already-fetched storage when the atomic class matches the called class.
                if ($atomicClassLower === $classNameLower) {
                    $storage = $calledClassStorage;
                } else {
                    try {
                        $storage = $codebase->classlike_storage_provider->get($atomicClassLower);
                    } catch (\InvalidArgumentException) {
                        $newAtomics[] = $atomic;
                        continue;
                    }
                }

                if (
                    isset($storage->parent_classes[self::FACTORY_CLASS_LOWER])
                    && ($storage->template_types === null || $storage->template_types === [])
                ) {
                    $newAtomics[] = new Type\Atomic\TNamedObject(
                        $atomic->value,
                        $atomic->is_static,
                        $atomic->definite_class,
                        $atomic->extra_types,
                        $atomic->from_docblock,
                    );
                    $changed = true;
                    continue;
                }
            }

            $newAtomics[] = $atomic;
        }

        if ($changed && $newAtomics !== []) {
            $event->setReturnTypeCandidate(new Type\Union($newAtomics));
        }
    }
}
