--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardUnion\Guards {
    final class UnionPromptGuard
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

namespace GuardUnion\Agents {
    final class UnionGuardedAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardUnion\Guards\UnionPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardUnion\Guards\UnionPromptGuard()];
        }
    }

    function askUnion(\Laravel\Ai\Contracts\Agent|UnionGuardedAgent $agent, \Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return $agent->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
