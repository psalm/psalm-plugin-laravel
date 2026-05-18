--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function indexUserText(\Illuminate\Http\Request $request): \Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration {
    // Stored prompt-injection / PoisonedRAG: user content embedded today
    // is read back into a future prompt verbatim.
    return \Laravel\Ai\Embeddings::for([(string) $request->input('note')]);
}
?>
--EXPECTF--
%ATaintedLlmPrompt on line %d: Detected tainted LLM prompt
