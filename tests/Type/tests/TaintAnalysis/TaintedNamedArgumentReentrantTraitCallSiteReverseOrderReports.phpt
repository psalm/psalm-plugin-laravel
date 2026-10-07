--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentReentrantTraitCallSiteReverseOrderReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

trait Dispatches
{
    public function go(): void
    {
        self::s(sink: tainted());
    }
}

final class FixedFirst
{
    use Dispatches;

    public static function s(string $sink = ''): void
    {
        system($sink);
    }
}

final class VariadicSecond
{
    use Dispatches;

    public static function s(string ...$rest): int
    {
        return \count($rest);
    }
}

/**
 * Reverse class order of `TaintedNamedArgumentReentrantTraitCallSiteReports.phpt`: the fixed
 * parameter is visited first and the variadic second, so the second visit must not leak its strip
 * onto the genuine finding recorded by the first.
 */
function reentrantVisitOrderDoesNotMatter(FixedFirst $first, VariadicSecond $second): void
{
    $first->go();
    $second->go();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
