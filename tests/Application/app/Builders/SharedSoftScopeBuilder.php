<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\SharedBuilderSoftModel;
use App\Models\SharedBuilderWithScopeModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom builder shared by {@see SharedBuilderSoftModel} (SoftDeletes, so `withTrashed()` is a trait
 * method) and {@see SharedBuilderWithScopeModel} (a `scopeWithTrashed()` of its own, no SoftDeletes).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class SharedSoftScopeBuilder extends Builder {}
