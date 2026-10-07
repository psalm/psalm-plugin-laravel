--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentTraitConcatReentrantReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

trait FixedThenVariadicDispatches
{
    public function go(): void
    {
        self::s(sink: tainted() . 'suffix');
    }
}

trait VariadicThenFixedDispatches
{
    public function go(): void
    {
        self::s(sink: tainted() . 'suffix');
    }
}

final class FixedFirst
{
    use FixedThenVariadicDispatches;

    public static function s(string $sink = ''): void
    {
        system($sink);
    }
}

final class VariadicSecond
{
    use FixedThenVariadicDispatches;

    public static function s(string ...$rest): int
    {
        return \count($rest);
    }
}

final class VariadicFirst
{
    use VariadicThenFixedDispatches;

    public static function s(string ...$rest): int
    {
        return \count($rest);
    }
}

final class FixedSecond
{
    use VariadicThenFixedDispatches;

    public static function s(string $sink = ''): void
    {
        passthru($sink);
    }
}

/**
 * Same trait shape as the ReentrantTraitCallSite fixtures, with one trait per visit order (a trait
 * body's nodes are shared by every user, so each order needs its own AST), but the value is a CONCATENATION. A
 * strip recorded on the concatenation node also removes taint from the expression-internal
 * `tainted()` -> concatenation edge, and Psalm shares that edge between every analysis of the
 * trait body, so a later visit under the variadic class erased the genuine finding of an earlier
 * visit under the fixed-parameter class. A call site inside a trait method is therefore never
 * stripped. Both visit orders are pinned.
 */
function fixedThenVariadic(FixedFirst $first, VariadicSecond $second): void
{
    $first->go();
    $second->go();
}

function variadicThenFixed(VariadicFirst $first, FixedSecond $second): void
{
    $first->go();
    $second->go();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
