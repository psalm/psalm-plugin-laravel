--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentReentrantTraitCallSiteReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

trait Dispatches
{
    public function go(): void
    {
        self::s(sink: tainted());
    }
}

final class VariadicFirst
{
    use Dispatches;

    public static function s(string ...$rest): int
    {
        return \count($rest);
    }
}

final class FixedSecond
{
    use Dispatches;

    public static function s(string $sink = ''): void
    {
        system($sink);
    }
}

/**
 * The trait body is analysed once per using class, and the SAME `StaticCall` and value AST nodes
 * are visited under each. `VariadicFirst::s()` declares a variadic, so the first visit records the
 * value as variadic-captured; `FixedSecond::s($sink)` binds the name to a fixed parameter, so the
 * second visit must clear that record or the genuine `TaintedShell` is erased.
 */
function reentrantVisitClearsStaleStrip(VariadicFirst $first, FixedSecond $second): void
{
    $first->go();
    $second->go();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
