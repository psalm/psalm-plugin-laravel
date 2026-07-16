<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Restores the `user_defined` flag on Eloquent cast contracts.
 *
 * The plugin registers framework `.phpstub` files, and Psalm scans them with
 * `Codebase::$register_stub_files = true`. During that pass the *real*
 * `CastsAttributes` / `CastsInboundAttributes` interface source is pulled in as
 * a transitive dependency and {@see \Psalm\Internal\PhpVisitor\Reflector\ClassLikeNodeScanner}
 * stamps it `user_defined = !register_stub_files` => `false`. That snapshot is
 * written to the classlike cache.
 *
 * On a warm cache the snapshot is reused, so `MethodComparator` treats the
 * interface as a stub: it compares the `@param array<string, mixed> $attributes`
 * docblock type (now `from_docblock = false`) as a *signature* type against an
 * implementer's native `array` (`array<array-key, mixed>`) and reports a
 * spurious `MethodSignatureMismatch`. A cold / `--no-cache` run rescans the
 * interface as a real dependency (`user_defined = true`) and the check is
 * skipped, so the error only appears with a populated cache.
 *
 * These interfaces are genuine first-party source, so restoring
 * `user_defined = true` after population is a correctness fix, not a
 * suppression: it makes the warm-cache result match the `--no-cache` result.
 */
final class CastContractUserDefinedHandler implements AfterCodebasePopulatedInterface
{
    private const CAST_CONTRACTS = [
        \Illuminate\Contracts\Database\Eloquent\CastsAttributes::class,
        \Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes::class,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        foreach (self::CAST_CONTRACTS as $fqcn) {
            if (!$provider->has($fqcn)) {
                continue;
            }

            $storage = $provider->get($fqcn);
            if (!$storage->user_defined) {
                $storage->user_defined = true;
            }
        }
    }
}
