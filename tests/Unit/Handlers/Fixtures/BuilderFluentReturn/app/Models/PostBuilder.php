<?php

declare(strict_types=1);

namespace BuilderFluentReturnFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends Builder<Post>
 */
final class PostBuilder extends Builder implements FluentContract
{
    public function publishedSelf(): self
    {
        return $this->where('published', true);
    }

    /**
     * Intersection, builder-primary: the first-listed member of an intersection type becomes the
     * top-level atomic, so this already matches without the extra_types fix — kept as a control.
     * Docblock-only (not a native intersection type): a native `self&FluentContract` return
     * type on an Eloquent\Builder subclass hits an unrelated pre-existing plugin issue that
     * collapses the declared type to `never`, unrelated to #1448.
     *
     * @return self&FluentContract
     */
    public function publishedIntersectionBuilderPrimary()
    {
        return $this->where('published', true);
    }

    /**
     * Intersection, builder-secondary: `self` is buried in the primary atomic's extra_types
     * (TypeParser::getTypeFromIntersectionTree() always demotes every member after the first),
     * so this is the exact shape the extra_types fix exists for.
     *
     * @return FluentContract&self
     */
    public function publishedIntersectionBuilderSecondary()
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
     * Static control: static methods are always checked regardless of probably_fluent — setting
     * it would be a no-op, so this must remain unaffected either way.
     */
    public static function forGuest(\Illuminate\Database\Query\Builder $query): static
    {
        return new self($query);
    }
}
