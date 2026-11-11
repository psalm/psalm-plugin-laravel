<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A non-Model ancestor that overrides `__callStatic`, inherited by {@see ImplicitQueryBuilderCallStaticMagicModel}.
 */
abstract class ImplicitQueryBuilderCallStaticMagicBase extends Model
{
    /**
     * @param string $method
     * @param array<array-key, mixed> $parameters
     */
    #[\Override]
    public static function __callStatic($method, $parameters): mixed
    {
        return parent::__callStatic($method, $parameters);
    }
}
