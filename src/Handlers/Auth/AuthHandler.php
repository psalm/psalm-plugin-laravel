<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

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
 * There are also Methods that return Guard instance (handed in {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardHandler}):
 * @see \Illuminate\Support\Facades\Auth::createSessionDriver()
 * @see \Illuminate\Support\Facades\Auth::createTokenDriver()
 * @see \Illuminate\Support\Facades\Auth::setRememberDuration()
 * @see \Illuminate\Support\Facades\Auth::setRequest()
 * @see \Illuminate\Support\Facades\Auth::forgetUser()
 */
final class AuthHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Support\Facades\Auth::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

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
     * Provide explicit parameter definitions for all methods handled by {@see getMethodReturnType}.
     *
     * In Psalm 7, returning null from a MethodParamsProvider for methods that only exist as
     * @method annotations on facades causes an UnexpectedValueException crash. We must return
     * explicit params for every method we handle.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/454
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        return match ($method_name_lowercase) {
            // Guard::user(), GuardHelpers::authenticate(), SessionGuard::getUser(),
            // SessionGuard::getLastAttempted() — all take no parameters
            'user', 'getuser', 'authenticate', 'getlastattempted' => [],

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
