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
 * The baseline shape: `label:` names a declared parameter and is also written at that
 * parameter's own offset, so nothing is captured by a variadic and NamedArgumentTaintHandler
 * must not strip it. The offset-independent siblings are
 * {@see TaintedNamedArgumentNameMatchAtAnyOffsetReports.phpt} and
 * {@see TaintedNamedArgumentReorderedNamesReports.phpt}.
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
