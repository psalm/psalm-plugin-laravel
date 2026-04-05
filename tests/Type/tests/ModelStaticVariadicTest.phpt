--FILE--
<?php declare(strict_types=1);

use App\Models\Post;
use App\Models\User;

/**
 * Issue #216: Model::select() should accept variadic string arguments.
 *
 * Query\Builder::select() uses @psalm-variadic to accept both array and variadic
 * string arguments. When called statically on a Model via __callStatic, the variadic
 * flag must be propagated through ModelMethodHandler::getParamsWithVariadicFlag().
 */

/** Static call with multiple string args — the original #216 case. */
function test_static_select_variadic(): void
{
    $_result = User::select('name', 'email');
}

/** Static call with single arg. */
function test_static_select_single(): void
{
    $_result = User::select('name');
}

/** Static call with array arg. */
function test_static_select_array(): void
{
    $_result = User::select(['name', 'email']);
}

/** addSelect() is also @psalm-variadic — static call exercises getParamsWithVariadicFlag. */
function test_static_addselect_variadic(): void
{
    $_result = User::addSelect('name', 'email');
}

/** distinct() is also @psalm-variadic — static call exercises getParamsWithVariadicFlag. */
function test_static_distinct_variadic(): void
{
    $_result = User::distinct('name');
}

/** Variadic static call on model with custom builder (PostBuilder). */
function test_custom_builder_static_select_variadic(): void
{
    $_result = Post::select('title', 'body');
}
?>
--EXPECTF--
