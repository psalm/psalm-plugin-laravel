<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Builders;

use DynamicWhereFixture\Models\CustomFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<CustomFilter>
 *
 * @method string whereKind(int $kind) A `@method` pseudo-method (as a local macro would be modelled).
 */
final class CustomFilterBuilder extends Builder
{
    /**
     * Takes a string where the schema column is an int: a call with a string only type-checks when
     * this declared method wins over the schema-derived dynamic where.
     *
     * @return $this
     */
    public function whereUserId(string $userId): static
    {
        return $this->where('user_id', $userId);
    }
}
