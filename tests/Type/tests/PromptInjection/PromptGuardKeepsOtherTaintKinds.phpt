--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
if (\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled()) {
    return;
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace GuardKindIso\Guards {
    final class KindIsoGuard
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

namespace GuardKindIso\Agents {
    final class KindIsoAgent implements \Laravel\Ai\Contracts\HasMiddleware
    {
        use \Laravel\Ai\Promptable;

        /**
         * @return list<\GuardKindIso\Guards\KindIsoGuard>
         */
        #[\Override]
        public function middleware(): array
        {
            return [new \GuardKindIso\Guards\KindIsoGuard()];
        }
    }

    function askKindIso(\Illuminate\Http\Request $request): void
    {
        $question = (string) $request->input('q');

        (new KindIsoAgent())->prompt($question);

        \Illuminate\Support\Facades\DB::select($question);
    }
}

?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
