<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ComposesArchivedRevisions;
use App\Models\Concerns\ComposesRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Provides parts to the shop.
 *
 * Composes revision relations through nested traits (#1613): revisions() is picked by `insteadof`
 * between two nested declarations, the other HasRevisions relations arrive by a single path.
 */
final class Supplier extends Model
{
    use ComposesRevisions, ComposesArchivedRevisions {
        ComposesArchivedRevisions::revisions insteadof ComposesRevisions;
    }

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
