--FILE--
<?php declare(strict_types=1);

$_user = \Illuminate\Support\Facades\Auth::user();
/** @psalm-check-type-exact $_user = \Illuminate\Foundation\Auth\User|null */

// All facade methods handled by AuthHandler must not crash Psalm 7
// (previously getUser/authenticate/etc. caused UnexpectedValueException
// because getMethodParams returned null for @method-annotated methods)
$_getUser = \Illuminate\Support\Facades\Auth::getUser();
/** @psalm-check-type-exact $_getUser = \Illuminate\Foundation\Auth\User|null */

$_authenticate = \Illuminate\Support\Facades\Auth::authenticate();
/** @psalm-check-type-exact $_authenticate = \Illuminate\Foundation\Auth\User */

$_loginUsingId = \Illuminate\Support\Facades\Auth::loginUsingId(1);
/** @psalm-check-type-exact $_loginUsingId = \Illuminate\Foundation\Auth\User|false */

$_onceUsingId = \Illuminate\Support\Facades\Auth::onceUsingId(1);
/** @psalm-check-type-exact $_onceUsingId = \Illuminate\Foundation\Auth\User|false */

$_getLastAttempted = \Illuminate\Support\Facades\Auth::getLastAttempted();
/** @psalm-check-type-exact $_getLastAttempted = \Illuminate\Foundation\Auth\User|null */

$_logoutOtherDevices = \Illuminate\Support\Facades\Auth::logoutOtherDevices('secret');
/** @psalm-check-type-exact $_logoutOtherDevices = \Illuminate\Foundation\Auth\User|null */

$_loginUsingIdWithRemember = \Illuminate\Support\Facades\Auth::loginUsingId(1, true);
/** @psalm-check-type-exact $_loginUsingIdWithRemember = \Illuminate\Foundation\Auth\User|false */

// Auth::guard() return type narrowing
$_guardWeb = \Illuminate\Support\Facades\Auth::guard('web');
/** @psalm-check-type-exact $_guardWeb = \Illuminate\Auth\SessionGuard */

$_guardNoArg = \Illuminate\Support\Facades\Auth::guard();
/** @psalm-check-type-exact $_guardNoArg = \Illuminate\Auth\SessionGuard */

/** @var string $guardName */
$guardName = 'web';
$_guardDynamic = \Illuminate\Support\Facades\Auth::guard($guardName);
// dynamic guard name — falls back to stub's declared union type
/** @psalm-check-type-exact $_guardDynamic = \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard */

$_guardUnknown = \Illuminate\Support\Facades\Auth::guard('nonexistent-guard');
// unknown guard name — falls back to stub's declared union type
/** @psalm-check-type-exact $_guardUnknown = \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard */

$_guardNull = \Illuminate\Support\Facades\Auth::guard(null);
// null is equivalent to no argument — narrows to the default guard's concrete class
/** @psalm-check-type-exact $_guardNull = \Illuminate\Auth\SessionGuard */

$_guardApi = \Illuminate\Support\Facades\Auth::guard('api');
/** @psalm-check-type-exact $_guardApi = \Illuminate\Auth\TokenGuard */

// DI-injected AuthManager — same narrowing as the facade (see issue #765)
function _diAuthManager(\Illuminate\Auth\AuthManager $authManager): void {
    $_amGuardWeb = $authManager->guard('web');
    /** @psalm-check-type-exact $_amGuardWeb = \Illuminate\Auth\SessionGuard */

    $_amGuardApi = $authManager->guard('api');
    /** @psalm-check-type-exact $_amGuardApi = \Illuminate\Auth\TokenGuard */

    $_amGuardNoArg = $authManager->guard();
    /** @psalm-check-type-exact $_amGuardNoArg = \Illuminate\Auth\SessionGuard */

    $_amGuardNull = $authManager->guard(null);
    // null is equivalent to no argument — narrows to the default guard's concrete class
    /** @psalm-check-type-exact $_amGuardNull = \Illuminate\Auth\SessionGuard */

    $_amGuardUnknown = $authManager->guard('nonexistent-guard');
    // unknown guard name — falls back to the declared union type
    /** @psalm-check-type-exact $_amGuardUnknown = \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard */

    $_amUser = $authManager->user();
    /** @psalm-check-type-exact $_amUser = \Illuminate\Foundation\Auth\User|null */

    $_amAuthenticate = $authManager->authenticate();
    /** @psalm-check-type-exact $_amAuthenticate = \Illuminate\Foundation\Auth\User */

    $_amGetUser = $authManager->getUser();
    /** @psalm-check-type-exact $_amGetUser = \Illuminate\Foundation\Auth\User|null */

    $_amGetLastAttempted = $authManager->getLastAttempted();
    /** @psalm-check-type-exact $_amGetLastAttempted = \Illuminate\Foundation\Auth\User|null */

    $_amAttempt = $authManager->attempt(['email' => 'me@example.com', 'password' => 'secret']);
    /** @psalm-check-type-exact $_amAttempt = bool */

    $_amAttemptRemember = $authManager->attempt(['email' => 'me@example.com', 'password' => 'secret'], true);
    /** @psalm-check-type-exact $_amAttemptRemember = bool */

    $_amAttemptWhen = $authManager->attemptWhen(
        ['email' => 'me@example.com', 'password' => 'secret'],
        static fn (): bool => true,
        true,
    );
    /** @psalm-check-type-exact $_amAttemptWhen = bool */

    $_amLoginUsingId = $authManager->loginUsingId(1);
    /** @psalm-check-type-exact $_amLoginUsingId = \Illuminate\Foundation\Auth\User|false */

    $_amLoginUsingIdWithRemember = $authManager->loginUsingId(1, true);
    /** @psalm-check-type-exact $_amLoginUsingIdWithRemember = \Illuminate\Foundation\Auth\User|false */

    $_amOnceUsingId = $authManager->onceUsingId(1);
    /** @psalm-check-type-exact $_amOnceUsingId = \Illuminate\Foundation\Auth\User|false */

    $authManager->logout();

    // Chained calls — the acceptance example from issue #765
    $_amChainedUser = $authManager->guard('web')->user();
    /** @psalm-check-type-exact $_amChainedUser = \Illuminate\Foundation\Auth\User|null */

    $_amChainedLogin = $authManager->guard('web')->loginUsingId(1);
    /** @psalm-check-type-exact $_amChainedLogin = \Illuminate\Foundation\Auth\User|false */

    // dynamic guard name — falls back to the declared union type
    /** @var string $dynamic */
    $dynamic = 'web';
    $_amGuardDynamic = $authManager->guard($dynamic);
    /** @psalm-check-type-exact $_amGuardDynamic = \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard */
}

// DI-injected by the Factory contract — narrowing applies through the interface too
function _diAuthFactory(\Illuminate\Contracts\Auth\Factory $factory): void {
    $_fGuardWeb = $factory->guard('web');
    /** @psalm-check-type-exact $_fGuardWeb = \Illuminate\Auth\SessionGuard */

    $_fGuardApi = $factory->guard('api');
    /** @psalm-check-type-exact $_fGuardApi = \Illuminate\Auth\TokenGuard */
}

// Direct concrete-guard DI — GuardHandler registers the concrete classes so that calls
// resolving to the concrete class (not the interface) still receive narrowing.
function _diSessionGuard(\Illuminate\Auth\SessionGuard $guard): void {
    $_sgUser = $guard->user();
    /** @psalm-check-type-exact $_sgUser = \Illuminate\Foundation\Auth\User|null */

    $_sgLogin = $guard->loginUsingId(1);
    /** @psalm-check-type-exact $_sgLogin = \Illuminate\Foundation\Auth\User|false */

    $_sgOnce = $guard->onceUsingId(1);
    /** @psalm-check-type-exact $_sgOnce = \Illuminate\Foundation\Auth\User|false */
}

function _diTokenGuard(\Illuminate\Auth\TokenGuard $guard): void {
    $_tgUser = $guard->user();
    /** @psalm-check-type-exact $_tgUser = \Illuminate\Foundation\Auth\User|null */
}

function _diRequestGuard(\Illuminate\Auth\RequestGuard $guard): void {
    $_rgUser = $guard->user();
    /** @psalm-check-type-exact $_rgUser = \Illuminate\Foundation\Auth\User|null */
}

// DI-injected by the StatefulGuard contract — covers cases where @mixin routes AuthManager
// methods here, but also direct usage if someone types against the interface.
function _diStatefulGuard(\Illuminate\Contracts\Auth\StatefulGuard $guard): void {
    $_stUser = $guard->user();
    /** @psalm-check-type-exact $_stUser = \Illuminate\Foundation\Auth\User|null */

    $_stLogin = $guard->loginUsingId(1);
    /** @psalm-check-type-exact $_stLogin = \Illuminate\Foundation\Auth\User|false */
}
?>
--EXPECT--
