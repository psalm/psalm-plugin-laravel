<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Custom Eloquent builder for the {@see AbstractSoftDeletable} archetype.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModel>
 */
class SoftDeletableBuilder extends Builder {}
