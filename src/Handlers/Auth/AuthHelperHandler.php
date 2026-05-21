<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Narrows the return type of the global `auth($guard)` helper to the concrete guard
 * class when the guard name is a known string literal and its driver maps to a standard
 * Laravel guard class (session/token).
 *
 * Without this narrowing, `auth('web')->logout()` fails with `UndefinedInterfaceMethod`
 * because the stub return type is the union `Guard|StatefulGuard` and `logout()` is
 * declared only on `StatefulGuard`. Narrowing to `SessionGuard` lets Psalm resolve the
 * concrete method. For a token-driver guard the narrowed type is `TokenGuard`, which
 * intentionally lacks `logout()` — that is the correct error, since calling logout on
 * a stateless guard fails at runtime too.
 *
 * The `auth()` / `auth(null)` no-argument case keeps the stub's `AuthManager` return
 * type. `AuthManager` carries `@mixin StatefulGuard`, so calls like `auth()->logout()`
 * already resolve correctly. Narrowing those calls here would lose access to manager-
 * only methods (`extend`, `provider`, `guard`, …) that the stub still exposes.
 *
 * Dynamic guard names (variable, unknown string) and custom drivers fall back to the
 * stub-declared union.
 *
 * @see \Psalm\LaravelPlugin\Handlers\Auth\AuthHandler::resolveGuardReturnType()
 *      for the equivalent narrowing on `Auth::guard()` / `AuthManager::guard()`.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/979
 */
final class AuthHelperHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['auth'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Type\Union
    {
        $call_args = $event->getCallArgs();

        // No-arg and literal-null cases resolve to AuthManager via the stub.
        // AuthManager's @mixin StatefulGuard covers logout/onceUsingId/viaRemember,
        // and narrowing here would shadow the manager-only methods.
        if ($call_args === []) {
            return null;
        }

        $first_arg = $call_args[0]->value;
        if ($first_arg instanceof ConstFetch && $first_arg->name->toLowerString() === 'null') {
            return null;
        }

        // Only narrow when the guard name is a literal string we can resolve.
        if (! $first_arg instanceof String_) {
            return null;
        }

        $fqcn = AuthConfigAnalyzer::instance()->getGuardFQCN($first_arg->value);
        if ($fqcn === null) {
            return null; // unknown guard or custom driver — fall back to stub
        }

        return new Type\Union([new TNamedObject($fqcn)]);
    }
}
