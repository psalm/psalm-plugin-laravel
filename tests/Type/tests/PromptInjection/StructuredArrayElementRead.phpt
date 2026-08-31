--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.11.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StructuredArrayElement;

/**
 * Reading one element back out of an array-typed LLM source keeps the taint.
 * Both entry points are sourced (the `toArray()` return by stub, the
 * `$structured` property by LlmOutputTaintHandler) and the element read carries
 * the edge to the sink.
 *
 * Per-file local sinks rather than `DB::select()`, and that is load-bearing.
 * The BFS in `TaintFlowGraph::connectSinksAndSources()` prunes any node it has
 * already visited with the same taint mask, so a sink another fixture in the
 * batch already reached is unreachable for a second flow. Routed through
 * `DB::select()` both functions below report nothing, which is how this fixture
 * was first written: as a "known limitation" with an empty expectation. The
 * limitation was the test setup, not Psalm. See "Testing-time pitfall: Psalm's
 * per-sink-node taint de-duplication" in docs/contributing/taint-analysis.md.
 */

/** @psalm-taint-sink sql $sql */
function structuredArrayQueryA(string $sql): int { return \strlen($sql); }

/** @psalm-taint-sink sql $sql */
function structuredArrayQueryB(string $sql): int { return \strlen($sql); }

function readStructuredElement(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    $payload = $response->toArray();

    if (isset($payload['body'])) {
        structuredArrayQueryA('SELECT * FROM notes WHERE body = ' . (string) $payload['body']);
    }
}

function readStructuredPropertyElement(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    if (isset($response->structured['body'])) {
        structuredArrayQueryB('SELECT * FROM notes WHERE body = ' . (string) $response->structured['body']);
    }
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
