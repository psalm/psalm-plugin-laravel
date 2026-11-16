<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom builder whose whereHas() hands its callback a second argument, so the runtime contract differs from
 * Laravel's one-argument `$callback($query)`.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class CallbackWrappingBuilder extends Builder
{
    /**
     * @param \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>|string $relation
     * @param (\Closure(Builder<Model>, string): mixed)|null $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    #[\Override]
    public function whereHas($relation, ?\Closure $callback = null, $operator = '>=', $count = 1)
    {
        return parent::whereHas(
            $relation,
            $callback instanceof \Closure ? fn(Builder $query): mixed => $callback($query, (string) $relation) : null,
            $operator,
            $count,
        );
    }
}
