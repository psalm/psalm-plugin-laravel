--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// KNOWN LIMITATION: a closure middleware can never be exempted, however it is annotated. The
// guard lives in the closure's body, and no declared type can carry an escape annotation for it.
// Caveat docblock: PromptGuardTaintHandler::middlewareCandidates().

namespace GuardClosure\Agents {
    final class ClosureMiddlewareAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\Closure>
         */
        #[\Override]
        public function middleware(): array
        {
            return [
                /** @psalm-taint-escape llm_prompt */
                static fn(string $prompt, \Closure $next): mixed => $next($prompt),
            ];
        }
    }

    function askClosure(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new ClosureMiddlewareAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
