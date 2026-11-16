<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Owner of {@see DocblockBuilderChild}, for dot paths and nested arrays through its unknown builder. */
final class DocblockBuilderOwner extends Model
{
    /** @return HasMany<DocblockBuilderChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(DocblockBuilderChild::class);
    }
}
