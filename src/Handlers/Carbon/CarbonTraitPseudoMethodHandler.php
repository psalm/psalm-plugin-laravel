<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Carbon;

use Composer\InstalledVersions;
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
 * Only entries declared by a `Carbon\Traits\*` trait are dropped, and only when the class (or an
 * ancestor) has a real method of that name. Class-level `@method` tags on `Carbon` /
 * `CarbonImmutable` (`year()`, `addWeeks()`, ...) have no real method behind them and stay. Running
 * after population also covers user subclasses, which received copies of the entries from their parent.
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

        foreach ($event->getCodebase()->classlike_storage_provider::getAll() as $storage) {
            self::dropShadowingPseudoMethods($storage);
        }
    }

    private static function dropShadowingPseudoMethods(ClassLikeStorage $storage): void
    {
        foreach ($storage->declaring_pseudo_method_ids as $methodName => $declaringId) {
            if (self::isShadowing($storage, $methodName, $declaringId->fq_class_name)) {
                unset($storage->pseudo_methods[$methodName], $storage->declaring_pseudo_method_ids[$methodName]);
            }
        }

        foreach ($storage->declaring_pseudo_static_method_ids as $methodName => $declaringId) {
            if (self::isShadowing($storage, $methodName, $declaringId->fq_class_name)) {
                unset(
                    $storage->pseudo_static_methods[$methodName],
                    $storage->declaring_pseudo_static_method_ids[$methodName],
                );
            }
        }
    }

    /** @psalm-mutation-free */
    private static function isShadowing(ClassLikeStorage $storage, string $methodName, string $declaringClass): bool
    {
        return \strncasecmp($declaringClass, self::CARBON_TRAIT_NAMESPACE, \strlen(self::CARBON_TRAIT_NAMESPACE)) === 0
            && isset($storage->declaring_method_ids[$methodName]);
    }
}
