--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentNameMatchAtAnyOffsetReports;

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
 * DIFFERENT locations. Sharing one sink lets either case stop being a live trigger while the
 * other keeps the file reporting, and the file would still pass.
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
 * A callee that pairs the named FIXED parameter with a trailing variadic. The variadic exists, so a
 * gate that asked only "does this callee declare a variadic?" would strip here; upstream's matcher
 * breaks on `$label` before ever reaching `$rest`, so the node is keyed by `$label`'s declared index
 * and must be preserved.
 *
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
 * `label:` is written at offset 0, where `$path` is declared, yet upstream keys the argument's
 * taint node by the DECLARED index of the parameter the name resolves to
 * (`DataFlowNode::getParameterOffset()`), so it reaches `$label`'s `html` sink and never
 * `$path`'s `file` sink. NamedArgumentTaintHandler must not strip it: the written offset is not
 * a reason to doubt the attribution.
 */
function nameMatchAtForeignOffsetReports(): void
{
    sink(label: tainted());
}

/** Same shape through a receiver the handler CAN resolve to one class. */
function resolvedReceiverNameMatchReports(Sink $sink): void
{
    $sink->report(label: tainted());
}

/**
 * Same shape through a `new UnresolvedSink()` receiver, which has no `vars_in_scope` entry, so
 * the declared parameters are unreachable. An unresolvable callee must preserve: upstream's
 * attribution does not depend on the plugin seeing the signature.
 */
function unresolvedReceiverNameMatchReports(): void
{
    (new UnresolvedSink())->report(label: tainted());
}

/** The name-matches-a-fixed-param-before-a-variadic shape, which must still report. */
function nameMatchesFixedParamBeforeVariadicReports(): void
{
    sinkWithTail(label: tainted());
}

/**
 * A dynamic callee has no `Name` node, so no candidate id exists and the arguments are
 * preserved. Vanilla Psalm resolves no callee here either and reports nothing, so this pins the
 * shape (no crash, no spurious emission) rather than discriminating a behaviour.
 */
function dynamicCalleeEmitsNothing(): void
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
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
