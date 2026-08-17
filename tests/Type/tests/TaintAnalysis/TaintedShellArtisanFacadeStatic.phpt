--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * `Artisan::call()` forwards to `Illuminate\Foundation\Console\Kernel::call()`, whose
 * `$command` is a shell sink. The contract-typed receiver is covered by
 * TaintedShellArtisanCall.phpt; this pins the facade static form.
 */

function facadeStaticCallIsTainted(Request $request): void
{
    Artisan::call((string) $request->input('command'));
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
