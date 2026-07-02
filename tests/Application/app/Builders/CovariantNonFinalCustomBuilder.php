<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template-covariant TModel of Model
 * @extends Builder<TModel>
 */
class CovariantNonFinalCustomBuilder extends Builder {}
