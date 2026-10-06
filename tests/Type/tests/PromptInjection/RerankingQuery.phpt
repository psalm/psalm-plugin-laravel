--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Laravel\Ai\Reranking;

function rerankUserQuery(\Illuminate\Http\Request $request): void {
    Reranking::of(['A document'])->rerank((string) $request->input('query'));
}

function rerankConstantQuery(): void {
    Reranking::of(['A document'])->rerank('trusted query');
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
