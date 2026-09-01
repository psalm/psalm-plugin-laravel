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

namespace GuardFinalRecv\Guards {
    final class FinalRecvPromptGuard
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

namespace GuardFinalRecv\Agents {
    class FinalRecvGuardedBase implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardFinalRecv\Guards\FinalRecvPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardFinalRecv\Guards\FinalRecvPromptGuard()];
        }
    }

    // A sibling that strips the stack, so the base's middleware() IS overridden downstream.
    final class FinalRecvStrippedSibling extends FinalRecvGuardedBase
    {
        /**
         * @return list<never>
         */
        #[\Override]
        public function middleware(): array
        {
            return [];
        }
    }

    // Final, and inherits the guarded middleware(): no subclass of THIS can substitute a stack,
    // so the sibling's override must not cost this receiver its exemption.
    final class FinalRecvAgent extends FinalRecvGuardedBase {}

    function askFinalRecv(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        return (new FinalRecvAgent())->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
