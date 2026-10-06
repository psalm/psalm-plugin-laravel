--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
