<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

use function is_string;

/**
 * Handles cases:
 * @see \Illuminate\Http\Request::user()
 * @see \Illuminate\Http\Request::user('guard-name')
 */
final class RequestHandler implements MethodReturnTypeProviderInterface
{
    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Http\Request::class];
    }

    /** @inheritDoc */
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if ($event->getMethodNameLowercase() !== 'user') {
            return null;
        }

        $guard_name = self::getGuardName($event->getStmt());
        if (! is_string($guard_name)) {
            return null;
        }

        $user_model_type = GuardHandler::getReturnTypeForGuard($guard_name);
        if (! $user_model_type instanceof Type\Atomic\TNamedObject) {
            return null;
        }

        return new Type\Union([
            $user_model_type,
            new Type\Atomic\TNull(),
        ]);
    }

    private static function getGuardName(MethodCall|StaticCall $stmt): ?string
    {
        $call_args = $stmt->getArgs();
        if ($call_args === []) {
            return 'default';
        }

        $first_arg_type = $call_args[0]->value;

        if ($first_arg_type instanceof String_) {
            return $first_arg_type->value;
        }

        // @todo support const, probably vars that contains string, probably enum value
        return null;
    }
}
