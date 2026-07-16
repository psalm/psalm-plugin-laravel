<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * User trait initializer that re-enables timestamps. Paired with a declared `$timestamps = false` and
 * `#[WithoutTimestamps]` in {@see \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TimestampsInitializerOrderModel},
 * this makes the outcome depend on WHERE `initializeHasTimestamps` runs in the walk, which is the only way
 * to observe that ordering: whether `initializeHasTimestamps()` sees `false` (and returns) or `true` (and applies
 * `#[WithoutTimestamps]`) is decided by whether this initializer ran first.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait EnablesTimestampsInInitializer
{
    public function initializeEnablesTimestampsInInitializer(): void
    {
        $this->timestamps = true;
    }
}
