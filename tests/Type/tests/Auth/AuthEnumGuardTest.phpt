--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.5.0');
--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Auth\TokenGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

enum Guards: string
{
    case Web = 'web';
    case Api = 'api';
}

enum IntGuards: int
{
    case Web = 1;
}

enum PureGuards
{
    case Web;
}

enum GuardsWithConstant: string
{
    public const DEFAULT = 'web';

    case Web = 'web';
}

// String-backed enum case resolves the guard name exactly like the literal string does.
$_guardWeb = Auth::guard(Guards::Web);
/** @psalm-check-type-exact $_guardWeb = SessionGuard */

$_guardApi = Auth::guard(Guards::Api);
/** @psalm-check-type-exact $_guardApi = TokenGuard */

function _diAuthManagerEnum(AuthManager $authManager): void
{
    $_amGuardWeb = $authManager->guard(Guards::Web);
    /** @psalm-check-type-exact $_amGuardWeb = SessionGuard */

    $_amUser = $authManager->guard(Guards::Web)->user();
    /** @psalm-check-type-exact $_amUser = User|null */
}

// Int-backed enums are out of scope — decline and fall back to the stub's declared union.
$_guardIntEnum = Auth::guard(IntGuards::Web);
/** @psalm-check-type-exact $_guardIntEnum = Guard|StatefulGuard */

// Pure (non-backed) enums are out of scope — decline and fall back to the stub's declared union.
$_guardPureEnum = Auth::guard(PureGuards::Web);
/** @psalm-check-type-exact $_guardPureEnum = Guard|StatefulGuard */

// A plain class constant on a string-backed enum is a ClassConstFetch too, but its name
// isn't a case — must decline (not crash) and fall back to the stub's declared union.
$_guardClassConstant = Auth::guard(GuardsWithConstant::DEFAULT);
/** @psalm-check-type-exact $_guardClassConstant = Guard|StatefulGuard */
?>
--EXPECTF--
