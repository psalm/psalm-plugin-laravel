--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Regression fixture for the legacy follow-up of psalm/psalm-plugin-laravel#874.
 *
 * Eloquent dispatches `$builder->active()` through `Model::callNamedScope()` which calls
 * `$this->{'scope'.ucfirst($scope)}(...)` (see vendor/laravel/framework/.../Model.php:1981).
 * Psalm cannot link the call site back to `scopeActive()` and reports PossiblyUnusedMethod
 * (UnusedMethod under findUnusedCode=true). SuppressHandler::suppressLegacyEloquentScopeMethods()
 * silences the public+protected variants Eloquent can actually dispatch on.
 *
 * Same caveats as the modern #[Scope] sibling test:
 *  - Lock-in under findUnusedCode is deferred to #869 (default type-test config has it off).
 *  - Private negative case is structurally unflaggable in Psalm 7: ClassLikes.php:1994 skips
 *    unused-method emission on private methods of classes with __call, and Model declares __call.
 */
final class LegacyScopeModel extends Model
{
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }
}

new LegacyScopeModel();
?>
--EXPECT--
PublicModelScope on line 29: Eloquent query scope scopeActive() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
