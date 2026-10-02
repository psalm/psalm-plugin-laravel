--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicAtWrittenOffsetReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function sinkAll(string ...$rest): void
{
    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

function sinkTail(string $safe, string ...$rest): void
{
    echo \htmlspecialchars($safe);

    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

/** @psalm-taint-sink file $a */
function sinkAfterTwo(string $a = '', string $b = '', string ...$rest): void
{
    echo $b;

    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

/** @psalm-taint-sink file $a */
function sinkPastDeclared(string $a = '', string $b = '', string ...$rest): void
{
    echo $b;

    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

/**
 * `getParameterOffset()` falls back to the argument's WRITTEN offset only for a variadic
 * parameter, so the node it produces is mis-keyed only when a NON-variadic parameter is declared
 * at that offset. In all three calls below the written offset IS the variadic's own declared
 * index, so upstream keys the node correctly and the handler must preserve: stripping here is
 * pure loss, not false-positive suppression.
 *
 * A separate sink function per offset so none can mask another: the variadic is declared at
 * offset 0, 1 and 2 respectively. `sinkAfterTwo` additionally carries a `file` sink on `$a`,
 * which must stay silent — nothing is mis-attributed to offset 0 here.
 */
function variadicAtOffsetZeroReports(): void
{
    sinkAll(cmd: tainted());
}

function variadicAtOffsetOneReports(): void
{
    sinkTail('ok', cmd: tainted());
}

function variadicAtOffsetTwoReports(): void
{
    sinkAfterTwo('safe', 'safe', zzz: tainted());
}

/**
 * Written offset 3, PAST every declared parameter (`$a`, `$b`, `$rest` occupy 0-2), so no parameter
 * exists for the node to collide with and the handler declines on `isset($params[$offset])`.
 *
 * This pins the absence of any emission, NOT a preserved finding: vanilla Psalm reports nothing for
 * this shape either — the node lands on offset 3, which carries no sink, so the value never reaches
 * `$rest`'s `echo`. Measured directly under a plugin-free config. So the branch cannot be given a
 * must-report assertion; what this case does catch is the handler starting to emit, or to suppress,
 * something here, and `$a`'s `file` sink staying silent.
 */
function offsetPastAllDeclaredParamsReports(): void
{
    sinkPastDeclared('safe', 'safe', 'safe', zzz: tainted());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
