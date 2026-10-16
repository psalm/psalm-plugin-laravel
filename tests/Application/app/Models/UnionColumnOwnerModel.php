<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Declaring model of a MorphTo over the union column models; its own `rank` is a float so a read off
 * the declaring model is distinguishable.
 *
 * @property float $rank
 */
final class UnionColumnOwnerModel extends Model
{
    /** @return MorphTo<UnionColumnIntModel|UnionColumnOtherIntModel, $this> */
    public function intTarget(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<UnionColumnIntModel|UnionColumnTextModel, $this> */
    public function mixedTarget(): MorphTo
    {
        return $this->morphTo();
    }
}
