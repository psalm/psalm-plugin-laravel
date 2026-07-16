<?php

declare(strict_types=1);

namespace AppendsFixture\Concerns;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * Merges a class cast through Laravel's `initialize<Trait>()` construction hook — the runtime path a
 * constructor-less warm-up instance skips. Backs the `meta` append on
 * {@see \AppendsFixture\Models\TraitCastBackedModel}.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasMeta
{
    public function initializeHasMeta(): void
    {
        $this->mergeCasts(['meta' => AsArrayObject::class]);
    }
}
