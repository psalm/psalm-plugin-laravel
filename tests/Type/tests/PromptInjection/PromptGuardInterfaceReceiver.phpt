--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardIface\Guards {
    final class IfacePromptGuard
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

namespace GuardIface\Agents {
    final class IfaceGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardIface\Guards\IfacePromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardIface\Guards\IfacePromptGuard()];
        }
    }

    function askIface(\Laravel\Ai\Contracts\Agent $agent, \Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return $agent->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
