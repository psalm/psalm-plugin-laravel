--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardRemembers\Guards {
    final class RemembersPromptGuard
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

namespace GuardRemembers\Agents {
    // laravel/ai's RememberConversation middleware titles a new conversation with a SECOND model
    // call that carries the raw prompt and bypasses the agent's middleware, so the guard's escape
    // does not hold for an agent that remembers conversations.
    final class RemembersChatAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;
        use \Laravel\Ai\Concerns\RemembersConversations;

        /**
         * @return list<\GuardRemembers\Guards\RemembersPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardRemembers\Guards\RemembersPromptGuard()];
        }
    }

    // The trait is detected up the parent chain, as RememberConversation::appliesTo() does.
    abstract class RemembersChatBase implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;
        use \Laravel\Ai\Concerns\RemembersConversations;

        /**
         * @return list<\GuardRemembers\Guards\RemembersPromptGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardRemembers\Guards\RemembersPromptGuard()];
        }
    }

    final class RemembersChatChild extends RemembersChatBase {}

    function askRemembering(\Illuminate\Http\Request $request, object $user): \Laravel\Ai\Responses\AgentResponse
    {
        $message = (string) $request->input('message');

        return (new RemembersChatAgent())->forUser($user)->prompt($message);
    }

    function askRememberingInherited(\Illuminate\Http\Request $request, object $user): \Laravel\Ai\Responses\AgentResponse
    {
        $message = (string) $request->input('message');

        return (new RemembersChatChild())->forUser($user)->prompt($message);
    }
}

?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
