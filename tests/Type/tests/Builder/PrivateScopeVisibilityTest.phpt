--FILE--
<?php declare(strict_types=1);

use App\Models\PrivateScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins #[Scope] VISIBILITY handling: a private #[Scope] is never a usable scope on any
 * supported Laravel (12-13) — 13.8+ rejects it in Model::isScopeMethodWithAttribute, and
 * earlier versions break at runtime anyway (it recurses through __call). So the plugin must
 * not treat it as a scope.
 *
 * Before the dispatch-truth work the plugin's #[Scope] detection was visibility-blind, so a
 * private #[Scope] alone was wrongly resolved as a scope (Builder<Model>). Now it is rejected:
 * a legacy scopeXxx twin wins, and a private #[Scope] with no twin stays unresolved.
 */

/** Private #[Scope] shadowed by a legacy twin: resolves via the legacy scopePublished(). */
function test_private_attribute_scope_falls_through_to_legacy(): void
{
    $_result = PrivateScopeModel::query()->published();
    /** @psalm-check-type-exact $_result = Builder<PrivateScopeModel> */
}

/**
 * Private #[Scope] with no legacy twin: not a usable scope, so the call is not resolved by the
 * scope handler and stays mixed (Builder::__call's runtime default), exactly as a nonexistent
 * builder method does.
 */
function test_private_attribute_scope_alone_is_not_a_scope(): void
{
    $_result = PrivateScopeModel::query()->archived();
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
