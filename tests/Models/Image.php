<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Image extends Model
{
    /**
     * Get the owning imageable model.
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
