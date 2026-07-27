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

namespace App\StructuredPayload;

function spreadAgentPayload(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // `$structured` is the decoded model output as a public array, the shape
    // laravel/ai's own ChatCommand reads. Psalm honors no taint annotation on a
    // property, so LlmOutputTaintHandler sources the read site.
    //
    // The sink takes the whole array on purpose. Pulling one element back out
    // loses the edge under whole-project analysis: see
    // StructuredArrayReturnKnownLimitation.phpt.
    extract($response->structured);
}

function spreadTextPayload(\Laravel\Ai\Responses\StructuredTextResponse $response): void {
    // Same trait, different parent, so the property is listed for both classes
    // rather than reached through the $text subclass walk.
    extract($response->structured);
}
?>
--EXPECTF--
TaintedExtract on line %d: Detected tainted extract
TaintedExtract on line %d: Detected tainted extract
