<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom builder that overrides `combineConstraints()`, a method between `with()` and the `$constraints($relation)`
 * call of eager loading, so an eager-load constraint closure may receive something other than the bare Relation.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class CombiningBuilder extends Builder
{
    /**
     * @param array<array-key, mixed> $constraints
     * @return \Closure
     */
    #[\Override]
    protected function combineConstraints(array $constraints)
    {
        return parent::combineConstraints($constraints);
    }
}
