<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture for psalm/psalm-plugin-laravel#1034.
 *
 * The scope is public so a `.phpt` can perform a direct instance call that passes the
 * $query builder explicitly ($model->hasAnyName($query, ...)). Such a call invokes the
 * real method and must be checked against its full signature, whereas the magic-forwarded
 * forms (DirectScopeModel::hasAnyName(...) / $builder->hasAnyName(...)) strip the leading
 * $query that Laravel injects.
 */
final class DirectScopeModel extends Model
{
    protected $table = 'direct_scope_models';

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $names
     */
    #[Scope]
    public function hasAnyName(Builder $query, array $names): void
    {
        $query->whereIn('name', $names);
    }

    /**
     * The issue's exact shape: a sibling #[Scope] method called directly, passing $query.
     *
     * Inside a registered model's own body the call's argument nodes have no recorded
     * type yet when the params provider fires, so detection resolves $query through the
     * Context::vars_in_scope fallback in BuilderScopeHandler::isDirectScopeCall().
     * Note: no suite analyzes this method body today (test:app builds a fresh app without
     * these fixtures; PHPT suites analyze only the snippet file), so this documents the
     * pattern; it was verified against the issue's reproduction.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $this->hasAnyName($query, ['a', 'b']);
    }

    /** @psalm-return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class);
    }
}
