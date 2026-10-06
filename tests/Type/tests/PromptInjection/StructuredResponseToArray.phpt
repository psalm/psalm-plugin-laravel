--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
if (\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled()) {
    return;
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StructuredExport;

function serializeStructuredPayload(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // toJson(), jsonSerialize() and __toString() all hand back the same decoded model
    // output. The keys came from the app's schema, the values did not.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $response->toJson());
}

function jsonSerializeStructuredPayload(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . (string) $response->jsonSerialize());
}

function castStructuredPayload(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // StructuredAgentResponse overrides AgentResponse::__toString() to serialize
    // $structured, which drops the parent stub's source annotation.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . (string) $response);
}

function offsetGetStructuredPayload(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // The explicit call is the covered counterpart to `$response['body']`, which
    // Psalm's array-access desugaring silently strips of taint. See
    // StructuredResponseArrayAccessKnownLimitation.phpt.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . (string) $response->offsetGet('body'));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
