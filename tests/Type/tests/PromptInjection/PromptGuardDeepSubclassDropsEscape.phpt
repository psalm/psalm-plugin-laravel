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

namespace GuardDeepGuard\Guards {
    class DeepGuardTrusted
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }

    class DeepGuardLevel2 extends DeepGuardTrusted {}

    class DeepGuardLevel3 extends DeepGuardLevel2 {}

    final class DeepGuardLevel4 extends DeepGuardLevel3
    {
        #[\Override]
        public function handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardDeepGuard\Agents {
    final class DeepGuardAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardDeepGuard\Guards\DeepGuardTrusted>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardDeepGuard\Guards\DeepGuardLevel4()];
        }
    }

    function askDeepGuard(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        return (new DeepGuardAgent())->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
