<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Concerns;

use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Casts\AsCollection;

/**
 * Merges a class cast from a `#[Initialize]`-tagged, NON-conventionally-named initializer (`seedViaAttribute`,
 * not `initializeSeedsCastViaAttribute`), so only the attribute-discovery branch of the warm-up replay
 * reaches it — a convention-only replay would miss it. `protected` to also exercise the reflection
 * (visibility-bypassing) invoke.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait SeedsCastViaAttribute
{
    #[Initialize]
    protected function seedViaAttribute(): void
    {
        $this->mergeCasts(['via_attr' => AsCollection::class]);
    }
}
