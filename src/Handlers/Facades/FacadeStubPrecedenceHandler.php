<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;

/**
 * Lets plugin-owned real methods on first-party facade stubs outrank Laravel's generated
 * `@method` catalogue by removing only the conflicting pseudo-static entries after Psalm's
 * populator has finished copying them into the facade hierarchy.
 *
 * Scoped to `stubs/common/Support/Facades/*`: userland app facades keep their declared
 * `@method` intent, and the fix rides Psalm's native real-method/template inference instead
 * of adding a parallel provider path just for first-party facades.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1368
 */
final class FacadeStubPrecedenceHandler implements AfterCodebasePopulatedInterface
{
    private const FACADE_NAMESPACE = 'Illuminate\\Support\\Facades\\';

    private const FACADE_BASE_LOWER = 'illuminate\\support\\facades\\facade';

    private const STUB_PATH_FRAGMENT = '/stubs/common/Support/Facades/';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $methodsToRemove = self::collectStubbedFacadeMethods($codebase);

        if ($methodsToRemove === []) {
            return;
        }

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            foreach ($methodsToRemove as $facadeLower => $methodNames) {
                if (\strtolower($storage->name) !== $facadeLower && !isset($storage->parent_classes[$facadeLower])) {
                    continue;
                }

                foreach (\array_keys($methodNames) as $methodName) {
                    unset($storage->pseudo_static_methods[$methodName]);
                }
            }
        }
    }

    /**
     * @return array<lowercase-string, array<lowercase-string, true>>
     * @psalm-external-mutation-free
     */
    private static function collectStubbedFacadeMethods(Codebase $codebase): array
    {
        $methodsToRemove = [];

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!self::isFirstPartyFacade($storage)) {
                continue;
            }

            foreach ($storage->methods as $methodName => $methodStorage) {
                if (!isset($storage->pseudo_static_methods[$methodName])) {
                    continue;
                }

                if (!self::comesFromFacadeStub($methodStorage)) {
                    continue;
                }

                $methodsToRemove[\strtolower($storage->name)][$methodName] = true;
            }
        }

        return $methodsToRemove;
    }

    /** @psalm-mutation-free */
    private static function isFirstPartyFacade(ClassLikeStorage $storage): bool
    {
        return \str_starts_with($storage->name, self::FACADE_NAMESPACE)
            && isset($storage->parent_classes[self::FACADE_BASE_LOWER]);
    }

    /** @psalm-mutation-free */
    private static function comesFromFacadeStub(MethodStorage $methodStorage): bool
    {
        if (!$methodStorage->stubbed) {
            return false;
        }

        $filePath = $methodStorage->location?->file_path ?? $methodStorage->stmt_location?->file_path;

        if ($filePath === null) {
            return false;
        }

        return \str_contains(\str_replace('\\', '/', $filePath), self::STUB_PATH_FRAGMENT);
    }
}
