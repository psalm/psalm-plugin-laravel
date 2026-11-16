<?php

declare(strict_types=1);

namespace App\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relation whose template order is swapped: the declaring model comes first, unlike HasMany's own order.
 *
 * @template TDeclaring of Model
 * @template TRelated of Model
 * @extends HasMany<TRelated, TDeclaring>
 */
final class SwappedHasMany extends HasMany {}
