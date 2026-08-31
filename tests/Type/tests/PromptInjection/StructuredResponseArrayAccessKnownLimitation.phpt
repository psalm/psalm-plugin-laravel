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

namespace App\StructuredOutput;

/**
 * Known limitation: Psalm drops the taint edge when it resolves the `$x['k']`
 * sugar into an `offsetGet()` call ([vimeo/psalm#11912]). Structured output is
 * still model output, so both sugar reads below should report and do not.
 *
 * Every sink here is a per-file local, and the explicit `offsetGet()` control at
 * the bottom is what makes the two silent expectations mean something. The BFS
 * in `TaintFlowGraph::connectSinksAndSources()` prunes nodes it has already
 * visited with the same taint mask, so a fixture that routes a "should not
 * report" assertion through a sink shared with the rest of the batch proves
 * nothing: it would stay silent even if the edge survived. The control fires
 * from the same source through an equivalent local sink, which pins that the
 * source and the sink are both live and the sugar is the only difference.
 *
 * When upstream lands, the two sugar reads start reporting and this test fails
 * loudly. Replace it then with positive assertions.
 */

/** @psalm-taint-sink html $html */
function structuredSugarSink(string $html): int { return \strlen($html); }

/** @psalm-taint-sink html $html */
function structuredTextSugarSink(string $html): int { return \strlen($html); }

/** @psalm-taint-sink html $html */
function structuredExplicitSink(string $html): int { return \strlen($html); }

function renderStructuredAgentField(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    structuredSugarSink((string) $response['summary']);
}

function renderStructuredTextField(\Laravel\Ai\Responses\StructuredTextResponse $response): void {
    // Same trait, different hierarchy (TextResponse rather than AgentResponse).
    structuredTextSugarSink((string) $response['summary']);
}

function renderStructuredAgentFieldExplicitly(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // Control: the desugared form of renderStructuredAgentField() above.
    structuredExplicitSink((string) $response->offsetGet('summary'));
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
