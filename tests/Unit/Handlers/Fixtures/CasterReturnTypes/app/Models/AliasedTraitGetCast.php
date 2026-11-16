<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** `get()` is a trait method under another name: the declaring method is `ReadsMoneyTrait::read`. */
final class AliasedTraitGetCast implements CastsAttributes
{
    use ReadsMoneyTrait {
        read as get;
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
