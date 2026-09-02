<?php

declare(strict_types=1);

namespace BuilderFluentReturnFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends Builder<Post>
 */
final class PostBuilder extends Builder
{
    public function publishedSelf(): self
    {
        return $this->where('published', true);
    }

    public function publishedStaticNative(): static
    {
        return $this->where('published', true);
    }

    /**
     * @return static
     */
    public function publishedStaticDocblock()
    {
        return $this->where('published', true);
    }

    public function publishedOwnClassName(): PostBuilder
    {
        return $this->where('published', true);
    }

    /**
     * Negative control: a non-fluent return, discarded everywhere, must still be reportable.
     *
     * @return Collection<int, Post>
     */
    public function discardedControl(): Collection
    {
        return $this->get();
    }

    /**
     * Static control: already exempt upstream via `is_static` regardless of this handler.
     */
    public static function forGuest(\Illuminate\Database\Query\Builder $query): static
    {
        return new self($query);
    }
}
