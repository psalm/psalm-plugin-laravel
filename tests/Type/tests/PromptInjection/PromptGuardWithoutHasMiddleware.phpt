--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardNoContract\Guards {
    final class NoContractPromptGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardNoContract\Agents {
    final class NoContractAgent
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardNoContract\Guards\NoContractPromptGuard>
         */
        public function middleware(): array
        {
            return [new \GuardNoContract\Guards\NoContractPromptGuard()];
        }
    }

    function askNoContract(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new NoContractAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
