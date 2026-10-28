<?php

declare(strict_types=1);

namespace ScopeUnusedCodeFixture\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ScopeUnusedCodeFixture\Concerns\HasInlineScopes;

/**
 * Hosts every scope shape at once so a single Psalm run covers them all under findUnusedCode:
 *  - trait #[Scope]   -> HasInlineScopes::active
 *  - trait legacy     -> HasInlineScopes::scopeFlagged
 *  - direct #[Scope]  -> self::published
 *  - direct legacy    -> self::scopeArchived
 * None are dispatched anywhere, so without SuppressHandler each would surface as
 * PossiblyUnusedMethod. `helperNonScope` is the surgical control: a non-scope method that MUST
 * stay reported, proving the suppressor only touches genuine scopes.
 */
final class TraitScopeModel extends Model
{
    use HasInlineScopes;

    /**
     * Direct #[Scope] (protected).
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('published', true);
    }

    /**
     * Direct legacy scope (public).
     *
     * @param Builder<self> $query
     */
    public function scopeArchived(Builder $query): void
    {
        $query->where('archived', true);
    }

    /**
     * Direct legacy accessor (no-regression control for the accessor path). Dispatched via __get
     * ($model->display_name), so it must stay suppressed.
     */
    protected function getDisplayNameAttribute(): string
    {
        return 'name';
    }

    /**
     * Control: neither #[Scope] nor scopeXxx, never called. Must STAY PossiblyUnusedMethod.
     *
     * @param Builder<self> $query
     */
    protected function helperNonScope(Builder $query): void
    {
        $query->where('legacy', true);
    }
}
