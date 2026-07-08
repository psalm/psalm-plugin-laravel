<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract generic parent in a two-level custom-builder hierarchy: declares a `static`-returning
 * fluent method, extended by concrete per-model builders that bind the template
 * (minimal reproduction shape from issue #1216).
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
