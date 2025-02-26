<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Auth;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Type;

use function in_array;
use function is_string;

/**
 * Handles cases (methods that return Authenticatable instance) [when called, "default" guard is used]:
 * @see \Illuminate\Support\Facades\Auth::user() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::loginUsingId() returns Authenticatable|false
 * @see \Illuminate\Support\Facades\Auth::onceUsingId() returns Authenticatable|false
 * @see \Illuminate\Support\Facades\Auth::logoutOtherDevices() returns Authenticatable|null
 * @see \Illuminate\Support\Facades\Auth::getLastAttempted() returns Authenticatable
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
    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return [\Illuminate\Support\Facades\Auth::class];
    }

    /** @inheritDoc */
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        if (
            ! in_array($method_name_lowercase, [
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
        if (! is_string($default_guard)) {
            return null; // normally should not happen (e.g. empty or invalid auth.php)
        }

        $authenticatable_fqcn = AuthConfigAnalyzer::instance()->getAuthenticatableFQCN($default_guard);

        if (! is_string($authenticatable_fqcn)) {
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
            default => null,
        };
    }

    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method_name_lowercase = $event->getMethodNameLowercase();

        if ($method_name_lowercase !== 'user') {
            return null;
        }

        return match ($method_name_lowercase) {
            'user' => [], // Explicitly define that user() has no parameters
        };
    }
}
