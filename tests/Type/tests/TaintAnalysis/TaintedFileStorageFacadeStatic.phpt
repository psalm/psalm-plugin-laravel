--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Facade static form: `Storage::get()` / `Storage::put()` resolve through
 * `__callStatic` + a class-level `@method` tag. A pseudo-method's params cannot
 * carry `@psalm-taint-sink`, so the sinks are copied from
 * `FilesystemAdapter` by FacadeTaintForwardingHandler at populate time.
 *
 * The last case pins the pre-existing `disk()->…` chain: the forwarding must add
 * the static path without disturbing the receiver-narrowing one.
 *
 * `(string)` keeps the value tainted while giving it a concrete type; a
 * `@var string` re-type would strip the taint.
 */

function facadeStaticGetIsTainted(Request $request): void
{
    Storage::get((string) $request->input('path'));
}

function facadeStaticPutIsTainted(Request $request): void
{
    Storage::put((string) $request->input('path'), 'contents');
}

function facadeDiskChainStillTainted(Request $request): void
{
    Storage::disk('local')->get((string) $request->input('path'));
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
