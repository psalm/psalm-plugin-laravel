<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Same column name as {@see UnionColumnOtherIntModel} and {@see UnionColumnTextModel}, for union-of-models pluck/aggregate tests.
 *
 * @property int $rank
 */
final class UnionColumnIntModel extends Model {}
