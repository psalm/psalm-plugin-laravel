<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * Trait initializer that throws on demand via the composing model's `fail()` switch, so the warm-up
 * trait-initializer replay fails and the four instance-derived sections degrade to incomplete. Used only
 * by {@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel} (whose private `fail()`
 * this method reaches once the trait is flattened in).
 *
 * @psalm-require-extends \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel
 */
trait FailsTraitInitializer
{
    public function initializeFailsTraitInitializer(): void
    {
        $this->fail('trait initializers');
    }
}
