<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors tests/Application/app/Builders/FavoriteableBuilder.php — abstract intermediate
 * builder declaring a `static`-returning fluent method, extended by a concrete per-model
 * builder that binds the template to a concrete model.
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
}
