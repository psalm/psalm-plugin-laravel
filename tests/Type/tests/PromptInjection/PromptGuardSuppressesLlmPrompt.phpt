--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardBasic\Guards {
    final class BasicPromptGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         * @psalm-flow ($prompt) -> return
         */
        public function handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardBasic\Agents {
    final class BasicGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardBasic\Guards\BasicPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardBasic\Guards\BasicPromptGuard()];
        }
    }

    function askBasic(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new BasicGuardedAgent())->prompt($question);
    }
}
?>
--EXPECTF--
