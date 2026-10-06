--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardClassString\Guards {
    final class ClassStringPromptGuard
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

namespace GuardClassString\Agents {
    final class ClassStringAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<class-string<\GuardClassString\Guards\ClassStringPromptGuard>>
         */
        #[\Override]
        public function middleware(): array
        {
            return [\GuardClassString\Guards\ClassStringPromptGuard::class];
        }
    }

    function askClassString(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new ClassStringAgent())->prompt($question);
    }
}

?>
--EXPECTF--
