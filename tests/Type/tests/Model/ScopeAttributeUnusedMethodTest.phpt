--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Regression fixture for psalm/psalm-plugin-laravel#874.
 *
 * Eloquent dispatches modern scopes through Builder::callNamedScope() / Builder::__call()
 * via reflection, so Psalm cannot link the `$builder->published()` call site back to the
 * model method declaration. Without SuppressHandler::suppressEloquentScopeMethods(), every
 * #[Scope] method on a user model would surface as PossiblyUnusedMethod (or UnusedMethod
 * under findUnusedCode=true).
 *
 * The type-test config has `findUnusedCode` off, so this fixture exercises only the plugin
 * code path today; it becomes a real regression guard once #869's findUnusedCode lock-in
 * lands. Smoke pattern matches NotificationTest.phpt / MailableTest.phpt.
 *
 * Private negative case is omitted: Psalm's ClassLikes::checkMethodReferences() skips
 * unused-method emission entirely for private methods on classes whose declaring class
 * has __call (see vendor/vimeo/psalm/src/Psalm/Internal/Codebase/ClassLikes.php:1994).
 * Eloquent\Model declares __call, so a private #[Scope] is structurally unflaggable —
 * the handler still routes private through suppressInternalDispatchMethod() defensively
 * (it would fatal at runtime, so silencing it would mask the bug).
 */
final class ScopeAttributeModel extends Model
{
    /** @param Builder<self> $query */
    #[Scope]
    public function publicPublished(Builder $query): void
    {
        $query->where('published_at', '<=', 'now');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function protectedArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }
}

new ScopeAttributeModel();
?>
--EXPECT--
PublicModelScope on line 34: Eloquent query scope publicPublished() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
