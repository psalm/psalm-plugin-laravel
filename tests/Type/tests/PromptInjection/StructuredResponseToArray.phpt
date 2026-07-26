--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
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
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
