--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardInvokable\Guards {
    final class InvokablePromptGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         * @psalm-flow ($prompt) -> return
         */
        public function __invoke(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardInvokable\Agents {
    final class InvokableAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardInvokable\Guards\InvokablePromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardInvokable\Guards\InvokablePromptGuard()];
        }
    }

    function askInvokable(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new InvokableAgent())->prompt($question);
    }
}

?>
--EXPECTF--
