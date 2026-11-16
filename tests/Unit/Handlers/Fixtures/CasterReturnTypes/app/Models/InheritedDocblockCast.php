<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use CasterReturnFixture\Money;
use Illuminate\Database\Eloquent\Model;

/** Overrides `get()` with the same wide native type and no docblock: the base class's `@return Money` still applies. */
final class InheritedDocblockCast extends BaseMoneyCast
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): object
    {
        return new Money((int) $value);
    }
}
