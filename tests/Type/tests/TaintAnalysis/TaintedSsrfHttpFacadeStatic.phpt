--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * `Http::get()` forwards to `Illuminate\Http\Client\PendingRequest::get()`, whose
 * `$url` is an ssrf sink. The `Http` facade root is `Client\Factory`, which forwards
 * to `PendingRequest` via `__call` — that pending object is what the
 * FacadeTaintForwardingHandler map points at.
 */

function facadeStaticGetIsTainted(Request $request): void
{
    Http::get((string) $request->input('embed_url'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
