--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentMismatchedPositionReports;

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

final class Sink
{
    /**
     * @psalm-taint-sink file $path
     * @psalm-taint-sink html $label
     */
    public function report(string $path = 'safe', string $label = 'x'): void
    {
        echo $path;
        echo $label;
    }
}

/**
 * A separate class so the resolved-receiver and unresolved-receiver cases below emit at
 * DIFFERENT locations. Sharing one sink lets either case stop reporting while the other keeps
 * the file non-empty, and the file would still pass.
 */
final class UnresolvedSink
{
    /**
     * @psalm-taint-sink file $path
     * @psalm-taint-sink html $label
     */
    public function report(string $path = 'safe', string $label = 'x'): void
    {
        echo $path;
        echo $label;
    }
}

/**
 * Every shape below must REPORT. Psalm 7.0.0-rc1 keys a named argument's taint node by the
 * DECLARED index of the parameter it names, so `label:` reaches `$label`'s `html` sink even
 * though it is written at offset 0, where `$path` is declared. Only `$label`'s sink may fire,
 * never `$path`'s `file` sink. None of these callees declares a variadic, so
 * NamedArgumentTaintHandler has nothing to strip.
 */

/** `label:` is written at offset 0, where `$path` is declared. */
function positionMismatchReports(): void
{
    sink(label: tainted());
}

/** The same shape through a receiver typed as exactly one class. */
function resolvedReceiverPositionMismatchReports(Sink $sink): void
{
    $sink->report(label: tainted());
}

/**
 * A `new UnresolvedSink()` receiver has no `vars_in_scope` entry, so the handler cannot read the
 * declared parameters. An unresolvable callee must preserve: Psalm's attribution does not
 * depend on the plugin seeing the signature.
 */
function unresolvedReceiverPositionMismatchReports(): void
{
    (new UnresolvedSink())->report(label: tainted());
}

/**
 * A dynamic callee has no `Name` node for the handler to resolve, yet Psalm resolves the
 * literal-string callee itself and keys `filename:` by its declared index.
 */
function dynamicCalleeReports(): void
{
    $fn = 'file_put_contents';
    $fn(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedFile on line %d: Detected tainted file handling
