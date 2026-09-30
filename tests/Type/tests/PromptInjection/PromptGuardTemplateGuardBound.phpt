--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardTpl\Guards {
    class TplTrustedGuard
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

namespace GuardTpl\Agents {
    /**
     * @template TGuard of \GuardTpl\Guards\TplTrustedGuard
     */
    final class TplGuardAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<class-string<TGuard>>
         */
        #[\Override]
        public function middleware(): array
        {
            return [\GuardTpl\Guards\TplTrustedGuard::class];
        }
    }

    /**
     * @param TplGuardAgent<\GuardTpl\Guards\TplTrustedGuard> $agent
     */
    function askTpl(\Illuminate\Http\Request $request, TplGuardAgent $agent): \Laravel\Ai\Responses\AgentResponse
    {
        return $agent->prompt((string) $request->input('q'));
    }
}

?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
