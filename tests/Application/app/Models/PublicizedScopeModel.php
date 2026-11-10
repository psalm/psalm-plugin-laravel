<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasFlaggedScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A protected trait `#[Scope]` re-exposed with `as public`: the method is accessible, so PHP never
 * reaches `__callStatic` and `PublicizedScopeModel::active()` is a runtime fatal.
 */
final class PublicizedScopeModel extends Model
{
    use HasFlaggedScope {
        active as public;
    }

    protected $table = 'publicized_scope_models';
}
