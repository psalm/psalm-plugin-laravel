<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\HasTitle as Titleable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

trait HasTitleRelation
{
    /** @return MorphTo<Model&Titleable, $this> */
    public function titleableThroughTrait(): MorphTo
    {
        return $this->morphTo();
    }
}
