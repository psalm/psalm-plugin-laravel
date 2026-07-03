<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Artist;

/**
 * Mirrors tests/Application/app/Builders/ArtistBuilder.php, specifically to exercise the
 * INSIDE-builder-body path that a `.phpt` (single-file psalm-tester mode) cannot cover: this
 * fixture's `<projectFiles>` includes `app/`, so Psalm ANALYZES this method body (not just
 * scans it for reflection), reproducing koel's actual error site — `withUserContext()` chaining
 * `$this->accessible()->when(...)->withRatingSubquery()` through a PRIVATE method reached via
 * `$this`. Before the fix, the intermediate collapsed to the abstract FavoriteableBuilder
 * parent and `withRatingSubquery()` surfaced as a false UndefinedMagicMethod/UndefinedMethod.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 *
 * @extends FavoriteableBuilder<Artist>
 */
class ArtistBuilder extends FavoriteableBuilder
{
    /**
     * Chains through the abstract parent's `accessible()` (returns `static`) and
     * `Conditionable::when()` (returns `$this`) before calling a PRIVATE method declared on
     * THIS concrete subclass. The intermediate type must stay ArtistBuilder, not collapse to
     * the abstract parent FavoriteableBuilder, or `withRatingSubquery()` is a false
     * UndefinedMagicMethod/UndefinedMethod.
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
}
