--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentTraitReentrantReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

// A trait body is analysed once per using class over the SAME AST nodes, so a strip recorded for
// the variadic user must not erase the genuine finding of the fixed-parameter user, in either
// visit order. The concatenation variants also share the expression-internal source -> concat
// edge between visits, which a WeakMap verdict cannot undo: call sites in a trait are declined.
// One trait per shape and order, because each trait's nodes are shared by its own users only.

trait PlainFixedFirst
{
    public function go(): void { self::s(sink: tainted()); }
}

trait PlainVariadicFirst
{
    public function go(): void { self::s(sink: tainted()); }
}

trait ConcatFixedFirst
{
    public function go(): void { self::s(sink: tainted() . 'suffix'); }
}

trait ConcatVariadicFirst
{
    public function go(): void { self::s(sink: tainted() . 'suffix'); }
}

final class PlainFixedA
{
    use PlainFixedFirst;

    public static function s(string $sink = ''): void { system($sink); }
}

final class PlainFixedB
{
    use PlainFixedFirst;

    public static function s(string ...$rest): int { return \count($rest); }
}

final class PlainVariadicA
{
    use PlainVariadicFirst;

    public static function s(string ...$rest): int { return \count($rest); }
}

final class PlainVariadicB
{
    use PlainVariadicFirst;

    public static function s(string $sink = ''): void { system($sink); }
}

final class ConcatFixedA
{
    use ConcatFixedFirst;

    public static function s(string $sink = ''): void { system($sink); }
}

final class ConcatFixedB
{
    use ConcatFixedFirst;

    public static function s(string ...$rest): int { return \count($rest); }
}

final class ConcatVariadicA
{
    use ConcatVariadicFirst;

    public static function s(string ...$rest): int { return \count($rest); }
}

final class ConcatVariadicB
{
    use ConcatVariadicFirst;

    public static function s(string $sink = ''): void { system($sink); }
}

function visits(
    PlainFixedA $a1,
    PlainFixedB $b1,
    PlainVariadicA $a2,
    PlainVariadicB $b2,
    ConcatFixedA $a3,
    ConcatFixedB $b3,
    ConcatVariadicA $a4,
    ConcatVariadicB $b4,
): void {
    $a1->go();
    $b1->go();
    $a2->go();
    $b2->go();
    $a3->go();
    $b3->go();
    $a4->go();
    $b4->go();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
