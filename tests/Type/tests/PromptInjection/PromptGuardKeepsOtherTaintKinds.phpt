--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
if (\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled()) {
    return;
}

// Psalm 7.0.0-beta21/beta22 drop one of two taint flows that reach the same sink method from two
// different files in a co-analyzed batch, so this fixture's finding disappears when the suite
// runs as a whole while it is still reported on its own.
// @todo-by 2026-10-10 drop this gate once vimeo/psalm#11959 ships a fix; if the issue is still
// open then, re-check whether the batch still hides the finding and move the date.
// @see https://github.com/vimeo/psalm/issues/11959
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta23');
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
