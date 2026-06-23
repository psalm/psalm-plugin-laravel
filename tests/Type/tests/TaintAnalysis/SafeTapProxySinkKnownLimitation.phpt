--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;

/**
 * Documented taint limitation of the higher-order tap proxy (issue #1110).
 *
 * `Mailable::to()` carries `@psalm-taint-sink header`, and a direct `$m->to($tainted)` DOES flag —
 * the committed positive TaintedHeaderMailableTo.phpt asserts exactly that, so the source/sink
 * wiring cannot silently regress and make this negative pass vacuously. But the no-arg `tap()`
 * form resolves the call to HigherOrderTapProxy::__call rather than to `Mailable::to()`'s own
 * signature, so the parameter-level sink is never consulted and `$m->tap()->to($tainted)` does
 * NOT flag.
 *
 * This empty --EXPECTF-- pins that gap: if a future change re-dispatches per-argument sinks
 * through the proxy (e.g. an AfterMethodCallAnalysis handler), this test will start reporting and
 * must be updated. The gap is narrow — every sink reachable via `tap()->method()` is also
 * reachable by calling `method()` directly, where the sink still fires.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1110
 */
function tap_proxy_sink_known_limitation(Mailable $m, Request $request): void
{
    $tainted = (string) $request->input('email');

    $m->tap()->to($tainted);
}
?>
--EXPECTF--
