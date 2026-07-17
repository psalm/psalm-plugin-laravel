<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

/**
 * Writes `$this->casts['raw_col']` directly (the exact idiom `SoftDeletes::initializeSoftDeletes()`
 * models for framework traits), bypassing `mergeCasts()` and its `ensureCastsAreStringValues()` call
 * entirely. An int value is used deliberately: `ensureCastsAreStringValues()` passes ints through its
 * `default` arm untouched regardless of walk order, so the resulting cast value is non-string on every
 * supported PHP version whether this initializer runs before or after the framework's own cast mirror —
 * unlike an array value, whose survival would depend on which side of that ordering wins.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait WritesRawCast
{
    public function initializeWritesRawCast(): void
    {
        $this->casts['raw_col'] = 123;
    }
}
