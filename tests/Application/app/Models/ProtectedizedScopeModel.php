<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A public trait `#[Scope]` narrowed with `as protected`: PHP forwards an outside static call to
 * `__callStatic`, but Psalm reads the trait method's own (public) visibility.
 */
class ProtectedizedScopeModel extends Model
{
    use HasPublicScope {
        published as protected;
    }

    protected $table = 'protectedized_scope_models';
}
