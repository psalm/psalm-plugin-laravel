<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Overrides the static `query()` entry point, so the inserted `query()->` would run this code and the
 * forwarded call would not.
 */
class ImplicitQueryBuilderCallQueryModel extends Model
{
    /** @return Builder<static> */
    #[\Override]
    public static function query()
    {
        return parent::query()->where('tenant_id', 1);
    }
}
