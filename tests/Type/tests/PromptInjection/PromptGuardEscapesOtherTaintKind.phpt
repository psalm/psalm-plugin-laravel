--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardHtmlOnly\Guards {
    final class HtmlOnlyPromptGuard
    {
        /**
         * @psalm-taint-escape html
         */
        public function handle(string $prompt, \Closure $next): mixed
        {
            return $next($prompt);
        }
    }
}

namespace GuardHtmlOnly\Agents {
    final class HtmlOnlyAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardHtmlOnly\Guards\HtmlOnlyPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardHtmlOnly\Guards\HtmlOnlyPromptGuard()];
        }
    }

    function askHtmlOnly(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        $question = (string) $request->input('q');

        return (new HtmlOnlyAgent())->prompt($question);
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
