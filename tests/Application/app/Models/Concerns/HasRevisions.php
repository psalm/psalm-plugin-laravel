<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Mechanic;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trait-hosted relations with bare native return types (#1613).
 *
 * Psalm keeps no method storage for these on the composing model, so the relation body is read
 * from this trait's file while `self::class` / `static::class` bind to the composing class.
 * Composed directly by WorkOrder and by the abstract AbstractDocument (trait-on-parent for Contract).
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasRevisions
{
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of')->latest();
    }

    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(static::class, 'revision_of');
    }

    public function revisionAuthor(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class, 'revised_by');
    }

    /** Delegates to a private helper, which binds to the composing class even if a child redeclares it. */
    public function priorRevisions(): HasMany
    {
        return $this->revisionChain()->latest();
    }

    private function revisionChain(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of');
    }
}
