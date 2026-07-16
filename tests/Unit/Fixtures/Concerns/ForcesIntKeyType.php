<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * Writes `$keyType` from a trait initializer, so the walk and the `initializeModelAttributes()` phase both
 * touch the same property. That collision is what makes the phase's POSITION observable — see
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\KeyTypeInitializerOrderModel}.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait ForcesIntKeyType
{
    public function initializeForcesIntKeyType(): void
    {
        $this->keyType = 'int';
    }
}
