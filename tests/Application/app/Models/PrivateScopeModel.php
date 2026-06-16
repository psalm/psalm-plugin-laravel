<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Archetype for #[Scope] VISIBILITY handling.
 *
 * A private #[Scope] is never a usable scope on any supported Laravel (12-13): 13.8+ rejects
 * it in Model::isScopeMethodWithAttribute, and earlier versions break at runtime anyway (it
 * recurses through __call, since callNamedScope dispatches from the base Model's $this where
 * the subclass's private method is unreachable). So the plugin treats it as not-a-scope — a
 * legacy scopeXxx twin (if any) wins, otherwise it is nothing.
 *
 * Kept isolated from the shared archetypes (Customer, Vehicle, …) so these visibility shapes
 * cannot perturb the many suites that import those models.
 */
final class PrivateScopeModel extends Model
{
    protected $table = 'private_scope_models';

    /**
     * Private #[Scope] shadowed by a legacy twin. The private attribute is never dispatchable
     * (see class docblock: 13.8+ rejects it, earlier versions recurse), so the only working
     * scope here is the legacy scopePublished() — the plugin must resolve via the legacy scope.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    private function published(Builder $query): void
    {
        $query->where('published', true);
    }

    /**
     * Legacy twin of the private #[Scope] above — this is what Laravel actually dispatches.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * Private #[Scope] with NO legacy twin: not dispatchable at runtime, so not a usable scope.
     * The plugin must NOT resolve it.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    private function archived(Builder $query): void
    {
        $query->where('archived', true);
    }
}
