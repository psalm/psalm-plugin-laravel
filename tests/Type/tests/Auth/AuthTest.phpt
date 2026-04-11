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
// null arg is treated like a dynamic value (not String_ AST node) — falls back to stub's declared union type
/** @psalm-check-type-exact $_guardNull = \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard */
?>
--EXPECT--
