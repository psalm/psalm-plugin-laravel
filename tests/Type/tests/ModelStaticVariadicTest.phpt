--FILE--
<?php declare(strict_types=1);

use App\Builders\PostBuilder;
use App\Models\Phone;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Issue #216: @psalm-variadic methods should accept variadic args when called
 * statically on Models or through Relation __call forwarding.
 *
 * Query\Builder::select() uses @psalm-variadic (internally func_get_args()).
 * ModelMethodHandler::getParamsWithVariadicFlag() propagates the storage-level
 * flag to the parameter level so Psalm allows extra arguments.
 */

// --- Model static calls (ModelMethodHandler) ---

/** Static call with multiple string args — the original #216 case. */
function test_static_select_variadic(): void
{
    $_result = User::select('name', 'email');
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

/** Static call with array arg. */
function test_static_select_array(): void
{
    $_result = User::select(['name', 'email']);
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

/** addSelect() is also @psalm-variadic. */
function test_static_addselect_variadic(): void
{
    $_result = User::addSelect('name', 'email');
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

/** distinct() has zero formal params — purely func_get_args(). */
function test_static_distinct_variadic(): void
{
    $_result = User::distinct('name');
    /** @psalm-check-type-exact $_result = Builder<User>&static */
}

/** Custom builder model returns PostBuilder<Post>, not base Builder. */
function test_custom_builder_static_select_variadic(): void
{
    $_result = Post::select('title', 'body');
    /** @psalm-check-type-exact $_result = PostBuilder<Post>&static */
}

// --- Arity preservation: required params still enforced ---

/** addSelect($column) requires at least one arg — variadic flag must not relax arity. */
function test_addselect_zero_args_still_fails(): void
{
    $_result = User::addSelect();
}

/** Non-variadic methods must still reject extra args. */
function test_non_variadic_too_many_args(): void
{
    $_result = User::orderBy('name', 'asc', 'extra');
}

// --- Relation instance calls (MethodForwardingHandler) ---

/** select() has its own stub on Relation — exercises Path 1 (mixin interception). */
/** @param HasOne<Phone, User> $r */
function test_relation_select_variadic(HasOne $r): void
{
    $_result = $r->select('name', 'email');
    /** @psalm-check-type-exact $_result = HasOne<Phone, User>&static */
}

/** addSelect/distinct go through Path 2 (MethodForwardingHandler __call). */
/** @param HasOne<Phone, User> $r */
function test_relation_addselect_variadic(HasOne $r): void
{
    $_result = $r->addSelect('name', 'email');
    /** @psalm-check-type-exact $_result = HasOne<Phone, User> */
}

/** @param HasOne<Phone, User> $r */
function test_relation_distinct_variadic(HasOne $r): void
{
    $_result = $r->distinct('name');
    /** @psalm-check-type-exact $_result = HasOne<Phone, User> */
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for App\Models\User::addselect - expecting column to be passed
TooManyArguments on line %d: Too many arguments for App\Models\User::orderby - expecting 2 but saw 3
