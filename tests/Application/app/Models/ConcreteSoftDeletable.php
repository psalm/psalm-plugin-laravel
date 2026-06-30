<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Concrete child of {@see AbstractSoftDeletable}. Detects the inherited custom builder normally —
 * `ConcreteSoftDeletable::query()` resolves to SoftDeletableBuilder<ConcreteSoftDeletable> — which
 * confirms gating custom builder detection for the abstract base does not regress concrete children.
 */
final class ConcreteSoftDeletable extends AbstractSoftDeletable
{
    protected $table = 'concrete_soft_deletables';
}
