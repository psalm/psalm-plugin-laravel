<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasTitle;
use App\Models\Concerns\HasTitleRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MorphIntersectionOwner extends Model
{
    use HasTitleRelation;

    /** @return MorphTo<Model&HasTitle, self> */
    public function titleable(): MorphTo
    {
        return $this->morphTo();
    }
}
