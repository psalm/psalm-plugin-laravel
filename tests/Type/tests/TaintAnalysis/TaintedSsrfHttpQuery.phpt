--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.19.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * `query()` (HTTP QUERY, Laravel 13.19.0+) sinks its URL like every other verb, on the instance,
 * async and facade forms (`stubs/13.19.0/Http/Client/PendingRequest.phpstub`).
 */

function instanceQuery(Request $request, PendingRequest $r): void { $r->query((string) $request->input('u')); }
function asyncQuery(Request $request, PendingRequest $r): void { $r->async()->query((string) $request->input('u')); }
function facadeQuery(Request $request): void { Http::query((string) $request->input('u')); }
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
