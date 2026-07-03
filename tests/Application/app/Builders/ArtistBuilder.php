<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Artist;

/**
 * Concrete custom builder that binds the abstract parent's template to a concrete model.
 *
 * Follows the minimal reproduction shape from issue #1216, reduced from koel's
 * App\Builders\ArtistBuilder: `extends FavoriteableBuilder<Artist>` (so the class itself is
 * non-generic), with its own fluent methods reached through a chain routed via the abstract
 * parent's `static`-returning method and Laravel's `Conditionable::when()`.
 *
 * (In real koel the parent's `static`-returning method is `withFavoriteStatus()` and koel's
 * `accessible()` lives on this child; the issue's minimal shape names the parent method
 * `accessible()` to keep the repro small. This fixture follows that shape.)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 *
 * @extends FavoriteableBuilder<Artist>
 */
class ArtistBuilder extends FavoriteableBuilder
{
    /**
     * Public entry point that chains through the abstract parent's `accessible()` (returns
     * `static`) and `Conditionable::when()` (returns `$this`) before calling a method declared
     * on THIS concrete subclass. The intermediate type must stay ArtistBuilder, not collapse to
     * the abstract parent FavoriteableBuilder, or `withRatingSubquery()` is a false
     * UndefinedMagicMethod.
     */
    public function withUserContext(): self
    {
        return $this
            ->accessible()
            ->when(true, static fn(self $q): self => $q)
            ->withRatingSubquery();
    }

    private function withRatingSubquery(): self
    {
        return $this;
    }

    /**
     * Public counterpart of {@see self::withRatingSubquery()} so a fluent chain that ends on a
     * concrete-subclass method can be exercised from outside the class (the type test calls it on
     * `Artist::query()->accessible()->when(...)->withPublicRating()`).
     */
    public function withPublicRating(): self
    {
        return $this;
    }
}
