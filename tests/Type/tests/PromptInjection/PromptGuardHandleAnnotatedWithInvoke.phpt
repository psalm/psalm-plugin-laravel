--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=1.0.0 <2.0.0)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardHandleWithInvoke\Guards {
    // handle() is what laravel/ai 1.x dispatches, so its escape exempts the call site even though
    // the class also declares an unannotated __invoke().
    final class HandleWithInvokeGuard
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

namespace GuardHandleWithInvoke\Agents {
    final class HandleWithInvokeAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardHandleWithInvoke\Guards\HandleWithInvokeGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardHandleWithInvoke\Guards\HandleWithInvokeGuard()];
        }
    }

    function askHandleWithInvoke(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new HandleWithInvokeAgent())->prompt($question);
    }
}

?>
--EXPECTF--
