<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for Mechanic model.
 *
 * Demonstrates the pattern of custom builders via static $builder property.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class MechanicBuilder extends Builder
{
    /** @psalm-return self<TModel> */
    public function whereCertified(): self
    {
        return $this->where('certified', true);
    }

    /**
     * A real custom method with the same name as Query\Builder's magic-forwarded groupBy().
     * PHP invokes this declaration before Builder::__call, so relation forwarding must use
     * this terminal signature rather than the Query Builder's fluent one.
     */
    public function groupBy(string $group): int
    {
        return \strlen($group);
    }
}
