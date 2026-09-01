--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=0.11.0 <1.0.0)';
}
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
                /**
                 * @psalm-taint-escape llm_prompt
                 */
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
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
