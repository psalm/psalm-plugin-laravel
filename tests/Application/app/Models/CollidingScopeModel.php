<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Archetype: a model whose scope names collide with real Builder/QueryBuilder
 * methods (find/count/latest) and with Laravel's dynamic-where parsing (whereActive).
 *
 * Kept isolated from the shared archetypes (Customer, Vehicle, …) so the colliding
 * scope names cannot perturb the many other suites that import those models.
 *
 * @property bool $active
 */
final class CollidingScopeModel extends Model
{
    protected $table = 'colliding_scope_models';

    /**
     * Collides with Eloquent\Builder::find(), a REAL method — so this scope is unreachable
     * dead code at runtime (__call never fires for find).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFind($query)
    {
        return $query->where('active', true);
    }

    /**
     * Collides with count(), which is passthru-only on Eloquent\Builder — so __call DOES fire
     * and this scope shadows the aggregate at runtime (the count()/sum()/exists() footgun).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCount($query)
    {
        return $query->where('active', true);
    }

    /**
     * Collides with Eloquent\Builder::latest(), a REAL method whose own return is the builder —
     * so this scope is dead code but the inferred Builder type still matches runtime.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatest($query)
    {
        return $query->where('active', true);
    }

    /**
     * Name parses as dynamic-where (whereActive -> where('active', ...)) but is
     * declared as a scope; Laravel's hasNamedScope() wins over dynamicWhere.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Collides with Query\Builder::orderBy(), a Query\Builder-ONLY method (forwarded via __call).
     *
     * Laravel's Builder::__call checks hasNamedScope() BEFORE forwardCallTo($this->query, ...),
     * so this scope wins at runtime — `Model::orderBy($priority)` dispatches here, not to
     * Query\Builder::orderBy($column, $direction). The params provider must mirror that order:
     * scope params (int $priority) must be checked before Query\Builder params (string $column).
     *
     * Distinct param type (int vs string) makes the precedence observable in type tests:
     * passing an int is valid for the scope and invalid for Query\Builder::orderBy, and
     * passing a string is invalid for the scope and valid for Query\Builder::orderBy.
     *
     * Scope-first precedence is fixed for STATIC model calls (Model::orderBy()). Builder-instance
     * calls (Model::query()->orderBy()) resolve through the Query\Builder mixin on Eloquent\Builder,
     * which rewrites the call to Query\Builder::orderBy before BuilderScopeHandler can be consulted,
     * so Query\Builder params win there. That is an upstream Psalm limitation, not fixable at the
     * plugin level (see test_instance_call_limitation in ScopeVsQueryBuilderParamsTest).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderBy($query, int $priority)
    {
        return $query->orderByRaw('priority = ?', [$priority]);
    }
}
