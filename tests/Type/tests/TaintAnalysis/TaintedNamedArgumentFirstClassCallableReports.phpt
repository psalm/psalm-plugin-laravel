--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentFirstClassCallableReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Runner
{
    public function run(string ...$rest): int
    {
        return \count($rest);
    }
}

/**
 * `$runner->run(...)` is a first-class callable: `getArgs()` throws on it, so the handler must
 * decline before reading arguments. The named call on the resulting closure goes through a
 * dynamic callee the handler never resolves. Pins "no crash, no spurious emission".
 */
function firstClassCallableDoesNotCrash(Runner $runner): void
{
    $run = $runner->run(...);
    $run(zzz: 'x');
}
?>
--EXPECTF--
