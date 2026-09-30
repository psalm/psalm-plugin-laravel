--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardBareArray\Guards {
    final class BareArrayPromptGuard
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

namespace GuardBareArray\Agents {
    final class BareArrayAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        #[\Override]
        public function middleware(): array
        {
            return [new \GuardBareArray\Guards\BareArrayPromptGuard()];
        }
    }

    function askBareArray(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new BareArrayAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
