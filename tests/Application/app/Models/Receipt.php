<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sibling of Contract under AbstractDocument: a second concrete child that inherits the
 * parent-composed ComparesRank scopes. Exists so a trait scope's `self` param — which binds
 * to the composing parent (AbstractDocument), not the queried child — can be exercised with a
 * *sibling* argument: `Contract::query()->rankedAbove($receipt)` is runtime-valid and must
 * type-check (issue #1031). A queried-model `self` pin would wrongly reject it.
 *
 * Also overrides the parent's documentParts() with another related model, so the parent's
 * delegating pendingParts() must follow runtime dispatch to this override (#1613).
 */
final class Receipt extends AbstractDocument
{
    protected $table = 'receipts';

    #[\Override]
    public function documentParts(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
