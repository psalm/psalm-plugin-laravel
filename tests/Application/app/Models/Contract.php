<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFlaggedScope;

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
}
