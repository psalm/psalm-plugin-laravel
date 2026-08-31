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
