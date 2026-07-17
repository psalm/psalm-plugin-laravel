<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract generic intermediate in a two-level custom-builder hierarchy, used to verify
 * that Builder::pluck() narrowing resolves TModel through a non-direct
 * @extends Builder<TModel> binding (issue #1287's "deeper chain" case).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1287
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
abstract class AbstractPluckableBuilder extends Builder {}
