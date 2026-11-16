--XFAIL--
Psalm 7.6 hops to the @mixin for an inaccessible method before consulting the called class's providers (vimeo/psalm#13769)
--FILE--
<?php declare(strict_types=1);

use App\Models\CollidingScopeModel;

// @todo-by https://github.com/vimeo/psalm/issues/13769 restore the protected-method scope case once Psalm consults the called class's providers before the mixin hop
/**
 * Protected-method collision: Eloquent\Builder::enforceOrderBy() is protected, so __call fires
 * and the scope wins. The params provider must NOT skip the hand-off write for non-public methods.
 *
 * Split out of ScopeHandoffLeakTest and marked XFAIL: since Psalm 7.6 (#13727) the call is resolved on the
 * `@mixin Query\Builder` of Eloquent\Builder, so BuilderScopeHandler, which is keyed on Eloquent\Builder,
 * never answers. The expectation below is the desired output; once it matches, the XFAIL turns into a
 * failure, which is the prompt to delete the section and fold these cases back into ScopeHandoffLeakTest.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/pull/1643#discussion_r4178549634
 */
function test_protected_method_collision_scope_wins(): void
{
    // scopeEnforceOrderBy(int $priority) should be invoked, not the protected Builder method.
    $_result = CollidingScopeModel::query()->enforceOrderBy(1);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\CollidingScopeModel> */
}

/** Passing wrong type to protected-collision scope should report against the SCOPE signature. */
function test_protected_method_collision_invalid_arg(): void
{
    // expects int $priority, given string — InvalidArgument from scope signature
    $_result = CollidingScopeModel::query()->enforceOrderBy('x');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::enforceOrderBy expects int, but 'x' provided
