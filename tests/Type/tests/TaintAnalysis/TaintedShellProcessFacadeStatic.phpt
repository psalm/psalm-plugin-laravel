--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * `Process::run()` forwards to `Illuminate\Process\PendingProcess::run()`, whose
 * `$command` is a shell sink. The `Process` facade root is `Process\Factory`, which
 * forwards to `PendingProcess` via `__call` — that pending object is what the
 * FacadeTaintForwardingHandler map points at.
 */

function facadeStaticRunIsTainted(Request $request): void
{
    Process::run((string) $request->input('command'));
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
