--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * `Http::get()` and the other verbs reach the `ssrf` sinks of the PendingRequest stub: the `Http`
 * facade stub's `@mixin PendingRequest` makes Psalm analyse the static call as the PendingRequest
 * call that `Client\Factory::__call` forwards to. The root alias and the async and chained forms
 * share the same sinks; named arguments keep `$url` the sink when reordered. A tainted query value or
 * HTTP method is not a URL and stays clean.
 */

function facadeGet(Request $request): void { Http::get((string) $request->input('u')); }
function facadePost(Request $request): void { Http::post((string) $request->input('u')); }
function facadePut(Request $request): void { Http::put((string) $request->input('u')); }
function facadePatch(Request $request): void { Http::patch((string) $request->input('u')); }
function facadeDelete(Request $request): void { Http::delete((string) $request->input('u')); }
function facadeHead(Request $request): void { Http::head((string) $request->input('u')); }
function facadeSend(Request $request): void { Http::send('GET', (string) $request->input('u')); }
function facadeSendNamed(Request $request): void { Http::send(url: (string) $request->input('u'), method: 'GET'); }
function rootAlias(Request $request): void { \Http::get((string) $request->input('u')); }
function asyncGet(Request $request): void { Http::async()->get((string) $request->input('u')); }
function chainedGet(Request $request): void { Http::withToken('t')->get((string) $request->input('u')); }
function taintedQueryValueIsClean(Request $request): void { Http::get('https://x.test', ['q' => (string) $request->input('u')]); }
function taintedMethodIsClean(Request $request): void { Http::send(method: (string) $request->input('m'), url: 'https://x.test'); }
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
