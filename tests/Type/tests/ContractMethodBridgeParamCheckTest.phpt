--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Foundation\Application;

/**
 * Guard: a bridged method keeps the CONCRETE's real param signature — arg-checking
 * stays live. useEnvironmentPath() with no arg (required $path) must raise
 * TooFewArguments naming the CONCRETE, proving the bridged id resolves to real
 * concrete storage, not a widened/empty signature.
 *
 * Psalm double-reports TooFewArguments for any interface call (contract receiver +
 * resolved declarer) — native behaviour, not a bridge artifact (setLocale() does
 * the same). %A absorbs the contract-named line; we pin only the concrete one. #1108.
 */
function param_check(Application $app): void
{
    $app->useEnvironmentPath();
}
?>
--EXPECTF--
%ATooFewArguments on line %d: Too few arguments for method Illuminate\Foundation\Application::useenvironmentpath saw 0%A
