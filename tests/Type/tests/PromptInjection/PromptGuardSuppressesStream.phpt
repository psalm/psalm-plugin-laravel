--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardStream\Guards {
    final class StreamPromptGuard
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

namespace GuardStream\Agents {
    final class StreamGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardStream\Guards\StreamPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardStream\Guards\StreamPromptGuard()];
        }
    }

    function askStream(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\StreamableAgentResponse
    {
        $question = (string) $request->input('q');

        return (new StreamGuardedAgent())->stream($question);
    }
}

?>
--EXPECTF--
