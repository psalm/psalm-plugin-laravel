<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ComparesRank;
use App\Models\Concerns\HasFlaggedScope;

/**
 * Child of AbstractDocument: exercises inherited and trait-hosted scopes
 * called on builder instances.
 *
 * Uses ComparesRank (self/static scope params) on a model WITHOUT a custom builder,
 * so its scope params provider registers on the base Illuminate Builder — the same
 * self/static misexpansion (issue #1031) is possible there and must also resolve to
 * the model. WorkOrder covers the custom-builder variant.
 */
final class Contract extends AbstractDocument
{
    use ComparesRank;
    use HasFlaggedScope;

    protected $table = 'contracts';
}
