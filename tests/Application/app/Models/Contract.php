<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFlaggedScope;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Child of AbstractDocument: exercises inherited and trait-hosted scopes
 * called on builder instances.
 *
 * Inherits ComparesRank from AbstractDocument — the trait is composed on the PARENT, not
 * here — so the trait's `self`-typed scope params resolve to AbstractDocument (the composing
 * class), making a sibling child such as Receipt an accepted argument (issue #1031). Contract
 * has no custom builder, so its scope params provider registers on the base Illuminate
 * Builder; WorkOrder covers the custom-builder variant and the directly-composed trait
 * (where `self` == the model itself).
 */
final class Contract extends AbstractDocument
{
    use HasFlaggedScope;

    protected $table = 'contracts';

    /** Delegates to the inherited documentParts() relation (#1613). */
    public function signedParts(): HasMany
    {
        return $this->documentParts()->whereNotNull('signed_at');
    }

    /**
     * Same name as HasRevisions' private helper composed on the parent. Private methods bind
     * lexically, so the trait's priorRevisions() still calls the parent's copy (#1613).
     */
    private function revisionChain(): HasMany
    {
        return $this->hasMany(Part::class);
    }
}
