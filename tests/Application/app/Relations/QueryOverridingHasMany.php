<?php

declare(strict_types=1);

namespace App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relation that overrides `getQuery()`, which Laravel forwards a nested eager-load constraint through.
 *
 * @template TRelated of Model
 * @template TDeclaring of Model
 * @extends HasMany<TRelated, TDeclaring>
 */
final class QueryOverridingHasMany extends HasMany
{
    /** @inheritDoc */
    #[\Override]
    public function getQuery()
    {
        return parent::getQuery();
    }
}
