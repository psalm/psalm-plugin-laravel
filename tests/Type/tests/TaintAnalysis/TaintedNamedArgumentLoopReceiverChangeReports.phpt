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
 * The loop re-visits the call with the receiver widened to `VariadicWriter|FixedWriter` (no longer
 * one known class); that visit must clear the first pass's strip or `FixedWriter::go()` loses its
 * genuine `TaintedShell`.
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
