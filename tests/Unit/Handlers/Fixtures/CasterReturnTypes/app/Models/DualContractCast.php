<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use CasterReturnFixture\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/** Implements both contracts: Laravel never calls `get()` for an inbound caster, the raw column value is read back. */
final class DualContractCast implements CastsAttributes, CastsInboundAttributes
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money((int) $value);
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
