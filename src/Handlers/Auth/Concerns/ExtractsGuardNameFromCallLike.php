<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth\Concerns;

use PhpParser\Node\Expr\CallLike;
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

        return null; // guard unknown
    }
}
