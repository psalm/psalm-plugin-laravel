<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Declares a method named like a query-builder link `__call` would otherwise forward.
 *
 * @extends Builder<LinkPost>
 */
final class LinkBuilder extends Builder
{
    public function orderBy(string $column): static
    {
        $this->query->join('authors', 'authors.id', '=', 'posts.author_id')->orderBy($column);

        return $this;
    }
}
