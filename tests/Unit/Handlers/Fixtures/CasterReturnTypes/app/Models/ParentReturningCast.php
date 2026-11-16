<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class ParentReturningCast extends StaticBaseCast
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): parent
    {
        return $this;
    }
}
