--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardNamedArg\Guards {
    final class NamedArgPromptGuard
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

namespace GuardNamedArg\Agents {
    final class NamedArgGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardNamedArg\Guards\NamedArgPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardNamedArg\Guards\NamedArgPromptGuard()];
        }
    }

    function askNamedArg(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new NamedArgGuardedAgent())->prompt(prompt: $question);
    }
}

?>
--EXPECTF--
