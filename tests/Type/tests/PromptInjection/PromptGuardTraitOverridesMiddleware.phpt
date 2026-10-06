--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardTrait\Guards {
    final class TraitPromptGuard
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

namespace GuardTrait\Agents {
    trait EmptyMiddleware
    {
        /**
         * @return list<never>
         */
        public function middleware(): array
        {
            return [];
        }
    }

    class TraitGuardedBase implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardTrait\Guards\TraitPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardTrait\Guards\TraitPromptGuard()];
        }

        public function ask(string $question): \Laravel\Ai\Responses\AgentResponse
        {
            return $this->prompt($question);
        }
    }

    final class TraitStrippedChild extends TraitGuardedBase
    {
        use EmptyMiddleware;
    }

    function askTrait(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        return (new TraitStrippedChild())->ask((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
