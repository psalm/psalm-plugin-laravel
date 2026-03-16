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
?>
--EXPECT--
