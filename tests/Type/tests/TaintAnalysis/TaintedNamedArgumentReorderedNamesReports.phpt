--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentReorderedNamesReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink html $a
 * @psalm-taint-sink file $b
 */
function f(string $a = '', string $b = ''): void
{
    echo $a;
    echo $b;
}

final class K
{
    /**
     * @psalm-taint-sink html $a
     * @psalm-taint-sink file $b
     */
    public static function m(string $a = '', string $b = ''): void
    {
        echo $a;
        echo $b;
    }
}

/**
 * Named arguments written in reverse declaration order: the tainted one sits at written offset
 * 1 while the parameter it names is declared at 0. The `html` sink on `$a` must report and the
 * `file` sink on `$b` — the parameter declared at the tainted argument's written offset — must
 * not. A separate sink class per call shape so neither can mask the other.
 */
function reorderedFunctionCallReports(): void
{
    f(b: 'safe', a: tainted());
}

function reorderedStaticCallReports(): void
{
    K::m(b: 'safe', a: tainted());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
