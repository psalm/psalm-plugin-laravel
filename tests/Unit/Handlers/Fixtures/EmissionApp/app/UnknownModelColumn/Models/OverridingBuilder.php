<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<OverriddenPost>
 */
final class OverridingBuilder extends Builder
{
    /** Maps legacy column names before delegating. */
    #[\Override]
    public function get($columns = ['*'])
    {
        return parent::get($columns === ['headline'] ? ['title'] : $columns);
    }
}
