<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * User trait initializer that `setAppends()` (the REPLACE form). At runtime, `bootTraits()` ranks this
 * concrete-class initializer ahead of Model's inherited `initializeHasAttributes`, so it runs BEFORE the
 * `#[Appends]` `mergeAppends()` and both entries survive. Guards that the warm-up replay runs before
 * `applyClassAttributeConfig()` (a replace after the merge would drop the attribute entry).
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
