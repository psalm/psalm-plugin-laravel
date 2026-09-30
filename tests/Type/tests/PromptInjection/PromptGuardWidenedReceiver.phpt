--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardWidened\Guards {
    final class WidenedPromptGuard
    {
        /**
         * @psalm-taint-escape llm_prompt
         */
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardWidened\Agents {
    class WidenedGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardWidened\Guards\WidenedPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardWidened\Guards\WidenedPromptGuard()];
        }
    }

    final class WidenedUnguardedChild extends WidenedGuardedAgent
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

    function widen(WidenedGuardedAgent $agent): WidenedGuardedAgent
    {
        return $agent;
    }

    function askWidened(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $agent = widen(new WidenedUnguardedChild());

        return $agent->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
