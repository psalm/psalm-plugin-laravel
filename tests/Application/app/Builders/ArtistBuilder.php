<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Artist;

/**
 * Concrete custom builder binding the abstract parent's template to a concrete model
 * (minimal reproduction shape from issue #1216).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 *
 * @extends FavoriteableBuilder<Artist>
 */
class ArtistBuilder extends FavoriteableBuilder
{
    /**
     * Chains through the abstract parent's `accessible()` (returns `static`) and
     * `Conditionable::when()` (returns `$this`) before calling a PRIVATE method declared on THIS
     * concrete subclass. The intermediate type must stay ArtistBuilder, not collapse to the
     * abstract parent, or `withRatingSubquery()` is a false UndefinedMagicMethod.
     *
     * This INSIDE-builder-body chain is the regression guard the type test cannot provide:
     * psalm-tester passes the phpt snippet as an explicit file argument, so referenced classes
     * are only reflected, never analyzed — method bodies here ARE analyzed because the
     * application test's <projectFiles> covers app/. Issue #1216's real-world error site was
     * exactly such an in-body chain.
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
