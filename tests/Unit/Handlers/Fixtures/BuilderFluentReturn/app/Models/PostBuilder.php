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
     * Union negative control: one non-builder arm means discarding the return can lose a real
     * result (the Collection branch), so the handler must decline and Psalm must keep reporting.
     */
    public function maybeCollection(bool $asCollection): self|Collection
    {
        return $asCollection ? $this->get() : $this->where('published', true);
    }

    /**
     * Static control: setting probably_fluent on a static method would be a no-op, because
     * ClassLikes::isStorageMethodOverridingUnused()'s `is_static || !probably_fluent` gate
     * short-circuits to true for statics regardless. The handler therefore skips statics
     * entirely — this element pins that it leaves them alone and does not crash on them, not
     * that statics are still checked (empirically, no static in this fixture shape ever
     * produces a discarded-return finding at all).
     */
    public static function forGuest(\Illuminate\Database\Query\Builder $query): static
    {
        return new self($query);
    }
}
