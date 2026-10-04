<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\DamageReport;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Declares revisions() like HasRevisions, so a model composing both must pick one with
 * `insteadof` (#1613): the parser cannot tell which body wins and must decline.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasArchivedRevisions
{
    public function revisions(): HasMany
    {
        return $this->hasMany(DamageReport::class, 'revision_of');
    }
}
