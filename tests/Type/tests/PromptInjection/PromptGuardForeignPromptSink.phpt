--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardForeign\Guards {
    final class ForeignPromptGuard
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

namespace GuardForeign\Agents {
    // Implements HasMiddleware and declares a guarded stack, but its prompt() is its own sink and
    // never routes through laravel/ai's pipeline, so the middleware is decoration.
    final class ForeignPromptAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        /**
         * @return list<\GuardForeign\Guards\ForeignPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardForeign\Guards\ForeignPromptGuard()];
        }

        /**
         * @psalm-taint-sink llm_prompt $prompt
         */
        public function prompt(string $prompt): string
        {
            return $prompt;
        }
    }

    function askForeign(\Illuminate\Http\Request $request): string
    {
        return (new ForeignPromptAgent())->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
