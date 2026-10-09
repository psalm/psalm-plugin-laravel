--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardInvokeOnly\Guards {
    // laravel/ai 1.x never calls __invoke() on a middleware entry (only handle(), or the closure
    // itself), so an escape declared only on __invoke() is never read and the finding stays.
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

namespace GuardInvokeOnly\Agents {
    final class InvokableAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardInvokeOnly\Guards\InvokablePromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardInvokeOnly\Guards\InvokablePromptGuard()];
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
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
