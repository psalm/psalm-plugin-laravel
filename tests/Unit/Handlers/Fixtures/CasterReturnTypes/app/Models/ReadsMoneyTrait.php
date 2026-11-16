<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use CasterReturnFixture\Money;
use Illuminate\Database\Eloquent\Model;

trait ReadsMoneyTrait
{
    public function read(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money((int) $value);
    }
}
