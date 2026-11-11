<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Brings a `newQuery()` override in through a trait.
 */
trait ImplicitQueryBuilderCallNewQueryTrait
{
    /** @return Builder<static> */
    #[\Override]
    public function newQuery()
    {
        return parent::newQuery()->where('tenant_id', 1);
    }
}
