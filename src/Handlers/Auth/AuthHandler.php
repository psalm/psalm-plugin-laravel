<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\LaravelPlugin\Handlers\Auth\Concerns\ExtractsGuardNameFromCallLike;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;

/**
 * Handles cases (methods that return Authenticatable instance) [when called, "default" guard is used]:
 * @see \Illuminate\Support\Facades\Auth::user() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::loginUsingId() returns Authenticatable|false
 * @see \Illuminate\Support\Facades\Auth::onceUsingId() returns Authenticatable|false
 * @see \Illuminate\Support\Facades\Auth::logoutOtherDevices() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::getLastAttempted() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::getUser() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::authenticate() returns Authenticatable
 *
 * Also narrows the return type of Auth::guard($name) to the concrete guard class when the guard
 * name is a known string literal and its driver is a standard Laravel driver (session/token):
 * @see \Illuminate\Support\Facades\Auth::guard() returns Guard|StatefulGuard (narrowed when possible)
 *
 * The same narrowing applies to non-static calls on DI-injected AuthManager instances and to
 * code typed against the \Illuminate\Contracts\Auth\Factory contract, since all three surfaces
 * proxy to the same underlying manager. See issue #765.
 *
 * Subsequent calls on the returned Guard instance (e.g. `Auth::guard('web')->user()`) are
 * narrowed by {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardHandler}.
 */
final class AuthHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    use ExtractsGuardNameFromCallLike;

    /**
     * Register for the Auth facade, the concrete AuthManager (common DI target), and the
     * Factory contract (DI by interface). The handler body below is class-agnostic — it
     * matches on method name only, so all three surfaces receive the same narrowing.
     *
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Support\Facades\Auth::class,
            \Illuminate\Auth\AuthManager::class,
            \Illuminate\Contracts\Auth\Factory::class,
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        if ($method_name_lowercase === 'guard') {
            return self::resolveGuardReturnType($event);
        }

        if (
            ! \in_array($method_name_lowercase, [
                'user',
                'loginusingid',
                'onceusingid',
                'logoutotherdevices',
                'getlastattempted',
                'getuser',
                'authenticate',
            ], true)
        ) {
            return null;
        }

        $default_guard = AuthConfigAnalyzer::instance()->getDefaultGuard();
        if (! \is_string($default_guard)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $authenticatable_fqcn = AuthConfigAnalyzer::instance()->getAuthenticatableFQCN($default_guard);

        if (! \is_string($authenticatable_fqcn)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        return match ($method_name_lowercase) {
            'user', 'logoutotherdevices', 'getuser', 'getlastattempted' => new Type\Union([
                new Type\Atomic\TNamedObject($authenticatable_fqcn),
                new Type\Atomic\TNull(),
            ]),
            'loginusingid', 'onceusingid' => new Type\Union([
                new Type\Atomic\TNamedObject($authenticatable_fqcn),
                new Type\Atomic\TFalse(),
            ]),
            'authenticate' => new Type\Union([
                new Type\Atomic\TNamedObject($authenticatable_fqcn),
            ]),
        };
    }

    /**
     * Narrows Auth::guard($name) to the concrete guard class when the guard name is a known
     * string literal and its driver maps to a standard Laravel guard class.
     * Returns null to fall back to the stub's declared type when narrowing is not possible
     * (dynamic guard name, unknown guard, or custom driver).
     */
    private static function resolveGuardReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $default_guard = AuthConfigAnalyzer::instance()->getDefaultGuard();
        if ($default_guard === null) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $guard_name = self::getGuardNameFromFirstArgument($event->getStmt(), $default_guard);
        if ($guard_name === null) {
            return null; // dynamic guard name — cannot narrow statically
        }

        $fqcn = AuthConfigAnalyzer::instance()->getGuardFQCN($guard_name);
        if ($fqcn === null) {
            return null; // unknown guard or custom driver
        }

        return new Type\Union([new Type\Atomic\TNamedObject($fqcn)]);
    }

    /**
     * Provide explicit parameter definitions for all methods handled by {@see getMethodReturnType}.
     *
     * In Psalm 7, returning null from a MethodParamsProvider for methods that only exist as
     * @method annotations on facades causes an UnexpectedValueException crash. We must return
     * explicit params for every method we handle.
     *
     * This override is scoped to the Auth facade only. For AuthManager and the Factory contract
     * the methods are real (or come from @mixin Guard/StatefulGuard), so Psalm can derive
     * parameter types from the source without our help. Returning our overrides there would
     * narrow signatures (e.g. guard()'s \UnitEnum|string|null parameter down to string|null)
     * and produce false positives on valid calls.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/454
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if ($event->getFqClasslikeName() !== \Illuminate\Support\Facades\Auth::class) {
            return null;
        }

        $method_name_lowercase = $event->getMethodNameLowercase();

        return match ($method_name_lowercase) {
            // Guard::user(), GuardHelpers::authenticate(), SessionGuard::getUser(),
            // SessionGuard::getLastAttempted() — all take no parameters
            'user', 'getuser', 'authenticate', 'getlastattempted' => [],

            // AuthManager::guard(?string $name = null)
            'guard' => [
                new FunctionLikeParameter(
                    'name',
                    false,
                    new Type\Union([new Type\Atomic\TString(), new Type\Atomic\TNull()]),
                    new Type\Union([new Type\Atomic\TString(), new Type\Atomic\TNull()]),
                    is_optional: true,
                    default_type: Type::getNull(),
                ),
            ],

            // SessionGuard::logoutOtherDevices(#[\SensitiveParameter] string $password)
            'logoutotherdevices' => [
                new FunctionLikeParameter('password', false, Type::getString(), Type::getString(), is_optional: false),
            ],

            // StatefulGuard::loginUsingId(mixed $id, bool $remember = false)
            'loginusingid' => [
                new FunctionLikeParameter('id', false, Type::getMixed(), Type::getMixed(), is_optional: false),
                new FunctionLikeParameter('remember', false, Type::getBool(), Type::getBool(), is_optional: true, default_type: Type::getFalse()),
            ],

            // StatefulGuard::onceUsingId(mixed $id)
            'onceusingid' => [
                new FunctionLikeParameter('id', false, Type::getMixed(), Type::getMixed(), is_optional: false),
            ],

            default => null,
        };
    }
}
