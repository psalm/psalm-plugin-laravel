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

namespace GuardForeign\Guards {
    final class ForeignPromptGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardForeign\Agents {
    // Implements HasMiddleware and declares a guarded stack, but its prompt() is its own sink and
    // never routes through laravel/ai's pipeline, so the middleware is decoration.
    final class ForeignPromptAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        /**
         * @return list<\GuardForeign\Guards\ForeignPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardForeign\Guards\ForeignPromptGuard()];
        }

        /**
         * @psalm-taint-sink llm_prompt $prompt
         */
        public function prompt(string $prompt): string
        {
            return $prompt;
        }
    }

    function askForeign(\Illuminate\Http\Request $request): string
    {
        return (new ForeignPromptAgent())->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
