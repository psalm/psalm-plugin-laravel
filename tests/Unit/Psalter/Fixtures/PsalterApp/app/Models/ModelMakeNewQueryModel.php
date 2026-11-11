<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Overrides only `newQuery()`, which `make()` goes through and `new` does not: the pending attributes are lost.
 */
class ModelMakeNewQueryModel extends Model
{
    /** @return Builder<static> */
    #[\Override]
    public function newQuery()
    {
        return parent::newQuery()->withAttributes(['title' => 'default-from-query']);
    }
}
