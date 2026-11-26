<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * BookStack's shape: the builder's constructor registers a joining global scope.
 *
 * @extends Builder<EntityPost>
 */
final class EntityBuilder extends Builder
{
    public function __construct(QueryBuilder $query)
    {
        parent::__construct($query);
        $this->withGlobalScope('entity', new JoinScope());
    }
}
