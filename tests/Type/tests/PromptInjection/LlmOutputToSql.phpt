--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function executeLlmCommand(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Classic LLM-output-to-SQL: model picks a column/condition based on user intent,
    // app interpolates the raw text into a raw query.
    \Illuminate\Support\Facades\DB::select($response->text);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
