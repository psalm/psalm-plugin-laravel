<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract intermediate builder in a two-level custom-builder hierarchy.
 *
 * Follows the minimal reproduction shape from issue #1216, reduced from koel's
 * App\Builders\FavoriteableBuilder: an abstract generic parent that declares a
 * `static`-returning fluent method, extended by concrete per-model builders that bind the
 * template to a concrete model (e.g. ArtistBuilder extends FavoriteableBuilder<Artist>).
 *
 * (Real koel names that parent method `withFavoriteStatus()`; this fixture calls it
 * `accessible()`, matching the issue's minimal-repro naming.)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
abstract class FavoriteableBuilder extends Builder
{
    /**
     * Fluent method declared on the abstract parent, returning the late-static-bound subclass.
     */
    public function accessible(): static
    {
        return $this;
    }

    /** Nullable native static: exercises the handler's null-atom passthrough. */
    public function accessibleOrNull(): ?static
    {
        return $this;
    }

    /** Native `: self`, must stay the declaring class, never rewritten to `static`. */
    public function notLateStaticBound(): self
    {
        return $this;
    }
}
