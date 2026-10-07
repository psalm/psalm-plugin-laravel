--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentPositionMatchReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink file $path
 * @psalm-taint-sink html $label
 */
function sink(string $path = 'safe', string $label = 'x'): void
{
    echo $path;
    echo $label;
}

/**
 * `label: tainted()` is written at offset 1, and the declared parameter at offset 1 is
 * `$label`: the plainest named-argument shape. The callee declares no variadic, so
 * NamedArgumentTaintHandler must not strip it.
 */
function positionMatchKeepsTaint(): void
{
    sink(path: 'safe', label: tainted());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
