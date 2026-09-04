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
