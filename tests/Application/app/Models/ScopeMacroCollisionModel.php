<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Archetype for the scope hand-off lifecycle: a model that declares a legacy scope whose name
 * collides with a SoftDeletes builder macro (withTrashed), WITHOUT using SoftDeletes itself.
 *
 * Calling this scope populates BuilderScopeHandler's producer->consumer hand-off for the
 * `withtrashed` key. The consumer must consume it once (unset) so the entry cannot leak and
 * shadow a genuine SoftDeletes withTrashed() macro on a different model analyzed afterwards.
 *
 * Kept isolated from the shared archetypes so this pathological scope name cannot perturb the
 * many suites that import Customer/Vehicle/etc.
 */
final class ScopeMacroCollisionModel extends Model
{
    protected $table = 'scope_macro_collision_models';

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithTrashed($query)
    {
        return $query;
    }
}
