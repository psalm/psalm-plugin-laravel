<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use function is_string;
use function in_array;

/**
 * Handles cases:
 * @see \Illuminate\Contracts\Auth\Guard::user() returns Authenticatable|null
 * @see \Illuminate\Contracts\Auth\StatefulGuard::loginUsingId returns Authenticatable|false
 * @see \Illuminate\Contracts\Auth\StatefulGuard::onceUsingId returns Authenticatable|false
 */
final class GuardHandler implements MethodReturnTypeProviderInterface
{
    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Contracts\Auth\Guard::class];
    }

    /** @inheritDoc */
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        if (! in_array($method_name_lowercase, ['user', 'loginusingid', 'onceusingid'], true)) {
            return null;
        }

        $statement = $event->getStmt();
        if (! $statement instanceof MethodCall) {
            return null;
        }

        $guard_name = self::findGuardNameInCallChain($statement);
        if (! is_string($guard_name)) {
            return null;
        }

        $user_model_type = self::getReturnTypeForGuard($guard_name);
        if (! $user_model_type instanceof Type\Atomic\TNamedObject) {
            return null;
        }

        return match ($method_name_lowercase) {
            'user' => new Type\Union([
                $user_model_type,
                new Type\Atomic\TNull(),
            ]),
            'loginusingid', 'onceusingid' => new Type\Union([
                $user_model_type,
                new Type\Atomic\TFalse(),
            ]),
            default => null,
        };
    }

    /**
     * @psalm-param lowercase-string $method_name_lowercase
     */
    public static function getReturnTypeForGuard(string $guard): ?Type\Atomic\TNamedObject
    {
        $user_model_fqcn = AuthConfigHelper::getAuthModel($guard);
        if (!is_string($user_model_fqcn)) {
            return null;
        }

        return new Type\Atomic\TNamedObject($user_model_fqcn);
    }

    private static function findGuardNameInCallChain(MethodCall $methodCall): ?string
    {
        $guard_method_call = null;

        $previous_call = $methodCall->var;
        while ($previous_call instanceof MethodCall) {
            if (($previous_call->name instanceof Identifier) && $previous_call->name->name === 'guard') {
                $guard_method_call = $previous_call;
                continue;
            }

            $previous_call = $previous_call->var;
        }
        unset($previous_call);

        if (! $guard_method_call instanceof MethodCall) {
            return null;
        }

        $guard_method_call_args = $guard_method_call->args;
        if ($guard_method_call_args === []) {
            return 'default';
        }

        $first_guard_method_arg = $guard_method_call_args[0];
        if ($first_guard_method_arg instanceof Arg && $first_guard_method_arg->value instanceof String_) {
            return $first_guard_method_arg->value->value;
        }

        // @todo how about null as arg or empty args?
        return null;
    }
}
