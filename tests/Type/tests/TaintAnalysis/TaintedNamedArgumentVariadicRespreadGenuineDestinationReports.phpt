--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicRespreadGenuineDestinationReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink file $directory
 * @psalm-taint-sink html $page
 */
function handle(?string $directory = null, string $page = ''): void
{
    echo (string) $directory;
    echo $page;
}

function run(string ...$arguments): void
{
    handle(...$arguments);
}

/**
 * The #1395 forwarder shape with the named argument's TRUE destination carrying a sink of its own.
 * `page:` is written at offset 0, which is `run`'s variadic's declared index, so upstream keys the
 * node correctly and the handler preserves it — and `$page`'s genuine `html` sink reports.
 *
 * This is the coverage that makes the reopened false positive in
 * {@see TaintedNamedArgumentVariadicRespreadSpreadFanOutFalsePositive.phpt} the right trade: the
 * strip that hid that false positive killed this finding at the same node, because both travel the
 * one source flow out of the call site. Preserving reports the mis-attributed `$directory` flow
 * too; that imprecision is Psalm's spread fan-out, not the named argument.
 */
function forwarderGenuineDestinationReports(): void
{
    run(page: tainted());
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
