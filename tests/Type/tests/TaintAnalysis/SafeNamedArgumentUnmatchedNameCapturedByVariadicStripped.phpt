--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentUnmatchedNameCapturedByVariadicStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink file $a
 * @psalm-taint-sink html $rest
 */
function v(string $a = '', string $b = '', string ...$rest): void
{
    echo $a;
    echo $b;

    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

/**
 * `zzz:` names no declared parameter, so `ArgumentsAnalyzer::checkArgumentsMatch()` matches it
 * against the variadic `$rest` — and `DataFlowNode::getParameterOffset()` bails to the WRITTEN
 * offset for a variadic parameter, so the node collides with `$a`, declared at offset 0. Vanilla
 * reports `TaintedFile` against `$a`'s `file` sink plus `TaintedHtml`/`TaintedTextWithQuotes` at
 * `echo $a`, all three mis-attributed; it reports nothing against `$rest`, the parameter the
 * value actually reaches. So the strip costs no measurable detection here.
 */
function unmatchedNameCapturedByVariadicIsStripped(): void
{
    v(zzz: tainted());
}
?>
--EXPECTF--
