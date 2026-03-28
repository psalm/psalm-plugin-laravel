--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Static calls to Query\Builder methods via Model::__callStatic should resolve
 * without UndefinedMagicMethod. These methods live on Query\Builder and are
 * forwarded through Eloquent\Builder at runtime via __call → forwardCallTo.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/498
 */

/** Query\Builder method forwarded through double mixin: Model → Builder → Query\Builder. */
function test_static_whereIn(): void
{
    $_result = User::whereIn('id', [1, 2, 3]);
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

function test_static_orderBy(): void
{
    $_result = User::orderBy('name');
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

/** Chaining: forwarded method → Eloquent\Builder method → terminal. */
function test_static_whereIn_chain_to_get(): void
{
    $_result = User::whereIn('id', [1, 2, 3])->get();
    /** @psalm-check-type-exact $_result = Collection<int, User> */
}

/** Chaining two forwarded Query\Builder methods. */
function test_static_chain_forwarded_methods(): void
{
    $_result = User::whereIn('id', [1, 2, 3])->orderBy('name')->get();
    /** @psalm-check-type-exact $_result = Collection<int, User> */
}

/** Regression: User::where() must still work (declared on Eloquent\Builder stub). */
/** @return Builder<User> */
function test_static_where(): Builder
{
    return User::where('active', true);
}

/** Regression: User::query() must still work. */
function test_static_query(): void
{
    $_result = User::query();
    /** @psalm-check-type-exact $_result = Builder<User> */
}

/** Legacy scope called statically: scopeActive → User::active(). */
function test_static_legacy_scope(): void
{
    $_result = User::active();
    /** @psalm-check-type-exact $_result = Builder<User> */
}

/**
 * Modern #[Scope] attribute: the idiomatic call is through the Builder.
 * This exercises BuilderScopeHandler::hasScopeAttribute via the Builder path.
 *
 * Calling User::verified() statically triggers InvalidStaticInvocation because
 * it's a real instance method — see test_scope_attribute_static_is_invalid below.
 */
/** @return Builder<User> */
function test_scope_attribute_via_builder(): Builder
{
    return User::query()->verified();
}

/** #[Scope] methods are real instance methods — static calls are correctly rejected. */
function test_scope_attribute_static_is_invalid(): void
{
    $_result = User::verified();
}

/** Negative test: non-existent methods must still be reported. */
function test_nonexistent_method(): void
{
    $_result = User::completelyFakeMethod();
}
?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
InvalidStaticInvocation on line %d: Method App\Models\User::verified is not static, but is called statically
UndefinedMagicMethod on line %d: Magic method App\Models\User::completelyfakemethod does not exist
