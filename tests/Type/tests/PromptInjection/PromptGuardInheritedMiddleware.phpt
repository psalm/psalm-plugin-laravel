--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardInherited\Guards {
    final class InheritedPromptGuard
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

namespace GuardInherited\Agents {
    abstract class InheritedGuardedBase implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardInherited\Guards\InheritedPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardInherited\Guards\InheritedPromptGuard()];
        }
    }

    final class InheritedChildAgent extends InheritedGuardedBase {}

    function askInherited(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new InheritedChildAgent())->prompt($question);
    }
}

?>
--EXPECTF--
