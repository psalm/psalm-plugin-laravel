--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentLoopReceiverChangeReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class VariadicWriter
{
    /** @psalm-impure */
    public function go(string ...$rest): int
    {
        return \count($rest);
    }
}

final class FixedWriter
{
    /** @psalm-impure */
    public function go(string $sink = ''): void
    {
        system($sink);
    }
}

/**
 * Loop analysis visits the same call node more than once while the receiver's type widens:
 * `VariadicWriter` on the first pass (the handler records the strip), `VariadicWriter|FixedWriter`
 * on the next, which is not one known class. The second visit must clear the first one's record,
 * or the genuine `TaintedShell` at `FixedWriter::go()` is lost.
 */
function receiverTypeChangesBetweenLoopPasses(int $times): void
{
    $writer = new VariadicWriter();

    for ($i = 0; $i < $times; $i++) {
        $writer->go(sink: tainted());
        $writer = new FixedWriter();
    }
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
