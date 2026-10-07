--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentNameMatchBeforeVariadicReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink file $path
 * @psalm-taint-sink html $label
 */
function sinkWithTail(string $path = 'safe', string $label = 'x', string ...$rest): void
{
    echo $path;
    echo $label;

    foreach ($rest as $chunk) {
        echo $chunk;
    }
}

/**
 * The callee declares a trailing variadic, but `label:` names a FIXED parameter declared before
 * it. Psalm's matcher breaks on `$label` before ever reaching `$rest`, so the argument is not a
 * variadic capture and the handler must preserve it. A gate asking only "does this callee declare
 * a variadic?" would strip it. `label:` is written at offset 0, where `$path` is declared, so the
 * `file` sink on `$path` must stay silent.
 */
function nameMatchesFixedParamBeforeVariadicReports(): void
{
    sinkWithTail(label: tainted());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
