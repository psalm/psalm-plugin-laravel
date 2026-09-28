<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth\Concerns;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\LaravelPlugin\Internal\Ast\ClassConstStringResolver;
use Psalm\StatementsSource;

trait ExtractsGuardNameFromCallLike
{
    public static function getGuardNameFromFirstArgument(CallLike $stmt, string $default_guard, StatementsSource $source): ?string
    {
        $call_args = $stmt->getArgs();
        if ($call_args === []) {
            return $default_guard;
        }

        $first_arg_type_expr = $call_args[0]->value;

        if ($first_arg_type_expr instanceof String_) {
            return $first_arg_type_expr->value;
        }

        // A literal null argument is equivalent to no argument — both resolve the default guard.
        // e.g. guard(null) behaves identically to guard() at runtime.
        if ($first_arg_type_expr instanceof ConstFetch && $first_arg_type_expr->name->toLowerString() === 'null') {
            return $default_guard;
        }

        return self::getGuardNameFromEnumCase($first_arg_type_expr, $source); // null when guard unknown
    }

    /**
     * Resolves `guard(Guards::Admin)` for a string-backed enum case to its backing value
     * ('admin'), the same guard name a literal `guard('admin')` resolves to.
     *
     * AST-based for the facade form (`Auth::guard(...)`); see {@see ClassConstStringResolver}.
     *
     * Deliberately declines (returns null) for:
     *  - int-backed enums — Laravel guard names are always strings.
     *  - pure (non-backed) enums — `enum_value()` falls back to `->name` for these at
     *    runtime, so they ARE technically resolvable, but the case name is not guaranteed
     *    to match a config guard key the way a backing value is, and this narrowing has
     *    not been scoped to cover that path yet (see issue #1389).
     *  - dynamic case expressions (e.g. `$class::$case`), `self`/`static`/`parent` (guard
     *    enums aren't declared inline in a way that makes those meaningful here), or
     *    classes Psalm cannot resolve to enum storage.
     */
    private static function getGuardNameFromEnumCase(Expr $expr, StatementsSource $source): ?string
    {
        return ClassConstStringResolver::backedEnumCase($expr, $source);
    }
}
