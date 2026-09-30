--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardWrongMethod\Guards {
    final class WrongMethodPromptGuard
    {
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }

        /**
         * @psalm-taint-escape llm_prompt
         */
        public function check(string $prompt): string
        {
            return $prompt;
        }
    }
}

namespace GuardWrongMethod\Agents {
    final class WrongMethodAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardWrongMethod\Guards\WrongMethodPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardWrongMethod\Guards\WrongMethodPromptGuard()];
        }
    }

    function askWrongMethod(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new WrongMethodAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
