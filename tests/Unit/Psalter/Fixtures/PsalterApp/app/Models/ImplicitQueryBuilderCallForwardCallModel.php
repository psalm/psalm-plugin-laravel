<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides `forwardCallTo()`, which every forwarded call (`where()`, `make()`) goes through: an inserted
 * `query()->` / `newQuery()->` or a plain `new` would skip that code.
 */
class ImplicitQueryBuilderCallForwardCallModel extends Model
{
    /**
     * @param mixed $object
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    protected function forwardCallTo($object, $method, $parameters): mixed
    {
        return parent::forwardCallTo($object, $method, $parameters);
    }
}
