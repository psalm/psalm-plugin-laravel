<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFlaggedScope;

/**
 * Child of AbstractDocument: exercises inherited and trait-hosted scopes
 * called on builder instances.
 */
final class Contract extends AbstractDocument
{
    use HasFlaggedScope;

    protected $table = 'contracts';
}
