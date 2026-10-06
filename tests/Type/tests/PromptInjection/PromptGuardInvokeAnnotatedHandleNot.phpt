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

namespace GuardInvokeNotHandle\Guards {
    // Regression for a false negative: laravel/ai 1.x dispatches a non-Closure entry through
    // handle() only (TextGenerationLoop::runStep), never __invoke(). An escape annotated on
    // __invoke() while the real guard path, handle(), is unannotated must keep the finding,
    // for an object entry and for a container-resolved class-string entry alike.
    final class InvokeAnnotatedPromptGuard
    {
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }

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

namespace GuardInvokeNotHandle\Agents {
    final class ObjectEntryAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardInvokeNotHandle\Guards\InvokeAnnotatedPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardInvokeNotHandle\Guards\InvokeAnnotatedPromptGuard()];
        }
    }

    final class ClassStringEntryAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<class-string<\GuardInvokeNotHandle\Guards\InvokeAnnotatedPromptGuard>>
         */
        #[\Override]
        public function middleware(): array
        {
            return [\GuardInvokeNotHandle\Guards\InvokeAnnotatedPromptGuard::class];
        }
    }

    function askObjectEntry(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new ObjectEntryAgent())->prompt($question);
    }

    function askClassStringEntry(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new ClassStringEntryAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
