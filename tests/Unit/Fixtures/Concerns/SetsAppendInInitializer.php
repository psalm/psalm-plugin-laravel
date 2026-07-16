<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * User trait initializer that `setAppends()` (the REPLACE form), so its result depends entirely on whether it
 * lands before or after `initializeHasAttributes`' `mergeAppends(#[Appends])`.
 *
 * Which one it is, is PHP-version-dependent — `getMethods()` ranks this concrete-class initializer first on
 * 8.5 (both entries survive) but Model's inherited concern initializer first on 8.4 (the replace lands last
 * and drops the attribute entry). Runtime disagrees with itself across the CI matrix, so the consuming test
 * carries no literal and reads a runtime oracle; see
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel}.
 *
 * What it guards is that the replay ranks the two exactly as `getMethods()` does, whichever PHP is running —
 * the same invariant {@see EnablesTimestampsInInitializer} pins for the timestamps initializer.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait SetsAppendInInitializer
{
    public function initializeSetsAppendInInitializer(): void
    {
        $this->setAppends(['trait_only']);
    }
}
