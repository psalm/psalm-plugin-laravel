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

/**
 * Lets a real Carbon method outrank a same-name `@method` tag that Carbon documents on one of its
 * own traits (`Carbon\Traits\Week` documents `dayOfYear(int $dayOfYear)`, `Serialization` documents
 * `locale()` and `toJSON()`, ...).
 *
 * Psalm's populator merges a trait's pseudo-methods into the using class without skipping names the
 * class really declares (the parent-class merge does skip them), and method resolution reads the
 * pseudo-method before the real one. The tag then hides the real signature, and with it the plugin's
 * conditional getter/setter stubs: `Carbon::dayOfYear()` inferred `static` instead of `int<1, 366>`.
 * `CarbonInterface` does not use those traits, so it was never affected.
 *
 * Only entries declared by a `Carbon\Traits\*` trait are dropped, and only when the class has a
 * real PUBLIC method of that name (a protected one such as `resolveCarbon()` is unreachable from
 * outside, so the tag keeps routing the call). Class-level `@method` tags on `Carbon` /
 * `CarbonImmutable` without a real method (`year()`, `addWeeks()`, ...) stay. Running after
 * population also covers user subclasses, which received copies of the entries from their parent.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2253
 * @see https://github.com/vimeo/psalm/issues/3937
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

        // A non-public real method (`resolveCarbon()`) is not callable from outside, so the public
        // tag is what makes the call resolve (Carbon dispatches it through `__call` macros).
        // Mirrors MethodVisibilityAnalyzer::analyze() (Call/Method/MethodVisibilityAnalyzer.php:112-123):
        // the declaring method's visibility, overridden by the APPEARING class's `trait_visibility_map`
        // entry for the appearing name (`use T { m as protected; }`, `use T { orig as m; }`); a
        // subclass does not copy that map.
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
