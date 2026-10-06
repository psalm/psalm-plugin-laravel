--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
    // The sink takes the whole array on purpose; the element-read path is
    // covered separately by StructuredArrayElementRead.phpt.
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
