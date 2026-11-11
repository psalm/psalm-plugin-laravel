<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Carbon;

use Composer\InstalledVersions;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;

// @todo-by https://github.com/vimeo/psalm/issues/13545 remove CarbonTraitPseudoMethodHandler and its registration
/**
 * Drops `@method` tags declared on Carbon's own traits (`Week::dayOfYear()`, `Serialization::locale()`, ...)
 * when the class has a real public method of that name. Psalm copies trait pseudo-methods onto the
 * using class without that check and resolves them first, which hides the real signature and the
 * plugin's conditional stubs (#2253).
 */
final class CarbonTraitPseudoMethodHandler implements AfterCodebasePopulatedInterface
{
    private const CARBON_TRAIT_NAMESPACE = 'Carbon\\Traits\\';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        if (!InstalledVersions::isInstalled('nesbot/carbon')) {
            return;
        }

        $storages = $event->getCodebase()->classlike_storage_provider;

        foreach ($storages::getAll() as $storage) {
            self::dropShadowingPseudoMethods($storages, $storage);
        }
    }

    private static function dropShadowingPseudoMethods(ClassLikeStorageProvider $storages, ClassLikeStorage $storage): void
    {
        foreach ($storage->declaring_pseudo_method_ids as $methodName => $declaringId) {
            if (self::isShadowing($storages, $storage, $methodName, $declaringId->fq_class_name)) {
                unset($storage->pseudo_methods[$methodName], $storage->declaring_pseudo_method_ids[$methodName]);
            }
        }

        foreach ($storage->declaring_pseudo_static_method_ids as $methodName => $declaringId) {
            if (self::isShadowing($storages, $storage, $methodName, $declaringId->fq_class_name)) {
                unset(
                    $storage->pseudo_static_methods[$methodName],
                    $storage->declaring_pseudo_static_method_ids[$methodName],
                );
            }
        }
    }

    /** @psalm-mutation-free */
    private static function isShadowing(
        ClassLikeStorageProvider $storages,
        ClassLikeStorage $storage,
        string $methodName,
        string $declaringClass,
    ): bool {
        if (\strncasecmp($declaringClass, self::CARBON_TRAIT_NAMESPACE, \strlen(self::CARBON_TRAIT_NAMESPACE)) !== 0) {
            return false;
        }

        $realMethodId = $storage->declaring_method_ids[$methodName] ?? null;

        if ($realMethodId === null) {
            return false;
        }

        // A non-public real method keeps its tag: an outside call goes through `__call` (macros).
        // Visibility is resolved as Psalm's MethodVisibilityAnalyzer does: the appearing class's
        // `trait_visibility_map` (subclasses keep no copy), else the declaring method's own.
        $appearingId = $storage->appearing_method_ids[$methodName] ?? null;

        try {
            $adapted = $appearingId === null
                ? null
                : $storages->get($appearingId->fq_class_name)->trait_visibility_map[$appearingId->method_name] ?? null;
            $visibility = $adapted
                ?? $storages->get($realMethodId->fq_class_name)->methods[$realMethodId->method_name]->visibility
                ?? null;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }

        return $visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC;
    }
}
