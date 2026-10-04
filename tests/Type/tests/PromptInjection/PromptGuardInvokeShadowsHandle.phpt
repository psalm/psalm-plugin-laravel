--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardShadowed\Guards {
    final class ShadowedHandleGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }

        public function __invoke(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardShadowed\Agents {
    final class ShadowedHandleAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardShadowed\Guards\ShadowedHandleGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardShadowed\Guards\ShadowedHandleGuard()];
        }
    }

    function askShadowed(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new ShadowedHandleAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
