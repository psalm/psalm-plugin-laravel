<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * `limit()` is not declared by Eloquent's Builder: `first()` and `sole()` reach it through `__call`.
 *
 * @extends Builder<LimitBuilderPost>
 */
final class LimitBuilder extends Builder
{
    public function limit(int $value): static
    {
        $this->query->limit($value)->join('authors', 'authors.id', '=', 'posts.author_id');

        return $this;
    }
}
