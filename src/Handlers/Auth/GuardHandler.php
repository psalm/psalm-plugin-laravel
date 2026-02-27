<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\LaravelPlugin\Handlers\Auth\Concerns\ExtractsGuardNameFromCallLike;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

use function in_array;
use function is_string;

/**
 * Handles cases (only non-static method calls):
 * @see \Illuminate\Contracts\Auth\Guard::user() returns Authenticatable|null
 * @see \Illuminate\Contracts\Auth\StatefulGuard::loginUsingId returns Authenticatable|false
 * @see \Illuminate\Contracts\Auth\StatefulGuard::onceUsingId returns Authenticatable|false
 */
final class GuardHandler implements MethodReturnTypeProviderInterface
{
    use ExtractsGuardNameFromCallLike;

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Contracts\Auth\Guard::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        if (! in_array($method_name_lowercase, ['user', 'loginusingid', 'onceusingid'], true)) {
            return null;
        }

        $authenticatables = AuthConfigAnalyzer::instance()->getAllAuthenticatables();
        if ($authenticatables === []) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $app_possible_authenticatable_types = [];

        foreach ($authenticatables as $authenticatable_fqcn) {
            $app_possible_authenticatable_types[] = new Type\Atomic\TNamedObject($authenticatable_fqcn);
        }

        $empty_return_type = $method_name_lowercase === 'user' ? new Type\Atomic\TNull() : new Type\Atomic\TFalse();
        $default_return_type = new Type\Union([...$app_possible_authenticatable_types, $empty_return_type]);

        $statement = $event->getStmt();
        if (! $statement instanceof MethodCall) { // in theory, it can also be a StaticCall
            return $default_return_type;
        }

        $guard = self::findGuardNameInCallChain($statement);

        $is_guard_known = is_string($guard);
        if (! $is_guard_known) {
            return $default_return_type;
        }

        $authenticatable_fqcn = AuthConfigAnalyzer::instance()->getAuthenticatableFQCN($guard);
        if (! is_string($authenticatable_fqcn)) {
            return $default_return_type;
        }

        return match ($method_name_lowercase) {
            'user' => new Type\Union([
                new Type\Atomic\TNamedObject($authenticatable_fqcn),
                new Type\Atomic\TNull(),
            ]),
            default => new Type\Union([ // 'loginusingid', 'onceusingid'
                new Type\Atomic\TNamedObject($authenticatable_fqcn),
                new Type\Atomic\TFalse(),
            ]),
        };
    }

    /**
     * Go backward in callstack in order to find
     * ->guard($guard) method call or auth($guard) helper call.
     * Return null when such method nof found (so we don't know which guard used).
     */
    private static function findGuardNameInCallChain(MethodCall $methodCall): ?string
    {
        $call_contains_guard_name = null;

        $previous_call = $methodCall->var;
        while ($call_contains_guard_name === null && $previous_call instanceof CallLike) {
            if ($previous_call instanceof MethodCall || $previous_call instanceof StaticCall) {
                if (($previous_call->name instanceof Identifier) && $previous_call->name->name === 'guard') {
                    $call_contains_guard_name = $previous_call; // exit from while loop
                    continue;
                }

                if ($previous_call instanceof MethodCall) {
                    $previous_call = $previous_call->var;
                    continue;
                }
            }

            // auth() or auth('guard') call
            if (
                $previous_call instanceof FuncCall
                && ($previous_call->name instanceof Name && $previous_call->name->getParts()[0] === 'auth')
            ) {
                $call_contains_guard_name = $previous_call;
                // exit from while loop
            }

            $previous_call = null; // exit from while loop
        }

        unset($previous_call);

        if (! $call_contains_guard_name instanceof CallLike) {
            return null;
        }

        $default_guard = AuthConfigAnalyzer::instance()->getDefaultGuard();
        if (! is_string($default_guard)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        return self::getGuardNameFromFirstArgument(
            $call_contains_guard_name,
            $default_guard,
        );
    }
}
