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

namespace GuardAddsInvoke\Guards {
    class AddsInvokeTrustedGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }

    // handle() is untouched, but the object is now callable, so Pipeline dispatches __invoke().
    final class AddsInvokeGuard extends AddsInvokeTrustedGuard
    {
        public function __invoke(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardAddsInvoke\Agents {
    final class AddsInvokeAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardAddsInvoke\Guards\AddsInvokeTrustedGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardAddsInvoke\Guards\AddsInvokeGuard()];
        }
    }

    function askAddsInvoke(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        return (new AddsInvokeAgent())->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
