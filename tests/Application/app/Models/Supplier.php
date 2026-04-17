<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Provides parts to the shop.
 */
final class Supplier extends Model
{
    protected $table = 'suppliers';

    /**
     * @psalm-return HasMany<Part>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    /**
     * Admin bookmarks for this supplier (inverse of Admin::suppliers()).
     *
     * @psalm-return MorphToMany<Admin>
     */
    public function bookmarkedAdmins(): MorphToMany
    {
        return $this->morphedByMany(Admin::class, 'bookmarkable');
    }
}
