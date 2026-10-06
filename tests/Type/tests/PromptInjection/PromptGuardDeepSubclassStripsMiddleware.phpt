--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardDeepRecv\Guards {
    final class DeepRecvPromptGuard
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

namespace GuardDeepRecv\Agents {
    class DeepRecvBase implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardDeepRecv\Guards\DeepRecvPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardDeepRecv\Guards\DeepRecvPromptGuard()];
        }

        // The journey label names this class whatever the runtime receiver is.
        public function ask(string $question): \Laravel\Ai\Responses\AgentResponse
        {
            return $this->prompt($question);
        }
    }

    class DeepRecvLevel2 extends DeepRecvBase {}

    class DeepRecvLevel3 extends DeepRecvLevel2 {}

    // Far enough down that a non-recursive descendant walk never reaches it.
    final class DeepRecvLevel4 extends DeepRecvLevel3
    {
        /**
         * @return list<never>
         */
        #[\Override]
        public function middleware(): array
        {
            return [];
        }
    }

    function askDeepRecv(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
    {
        return (new DeepRecvLevel4())->ask((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
