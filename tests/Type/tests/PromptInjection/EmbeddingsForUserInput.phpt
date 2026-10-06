--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function indexUserText(\Illuminate\Http\Request $request): \Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration {
    // Query embeddings are sent to a vector-search provider, not a generative
    // model. This ordinary search path must remain clean; Embeddings::for()
    // is intentionally not an llm_prompt sink.
    return \Laravel\Ai\Embeddings::for([(string) $request->input('note')]);
}
?>
--EXPECTF--
