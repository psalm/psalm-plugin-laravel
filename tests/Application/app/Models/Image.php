<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Image extends Model
{
    /**
     * Get the owning imageable model.
     *
     * Uses @return (not @psalm-return) to test the @return regex path.
     *
     * @return MorphTo<Post|User, $this>
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
