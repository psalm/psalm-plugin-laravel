<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides `__call`: `Model::where()` and `$model->where()` reach this method, while an inserted
 * `query()->` / `newQuery()->` would skip it.
 */
class ImplicitQueryBuilderCallMagicCallModel extends Model
{
    /**
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public function __call($method, $parameters): mixed
    {
        return parent::__call($method, $parameters);
    }
}
