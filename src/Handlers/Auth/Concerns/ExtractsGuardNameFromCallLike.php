<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth\Concerns;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;

trait ExtractsGuardNameFromCallLike
{
    public static function getGuardNameFromFirstArgument(CallLike $stmt, string $default_guard): ?string
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
        if ($first_arg_type_expr instanceof ConstFetch
            && $first_arg_type_expr->name->toLowerString() === 'null') {
            return $default_guard;
        }

        return null; // guard unknown
    }
}
