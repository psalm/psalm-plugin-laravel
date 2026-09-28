--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentStripped;

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
 * other keeps the file silent, and the file would still pass.
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
 * Every shape below must produce NOTHING. Upstream (vimeo/psalm#11923) keys a named argument's
 * taint node by its WRITTEN offset, so letting any of these through reports against the wrong
 * parameter — `$path`'s `file` sink instead of `$label`'s `html` sink.
 */

/** `label:` is written at offset 0, where `$path` is declared. Mismatch, so strip. */
function positionMismatchStripsAllTaint(): void
{
    sink(label: tainted());
}

/**
 * The receiver RESOLVES here, so the declared params are available and the handler takes its
 * preserve path — but `label:` still sits at offset 0. Resolving a receiver must never turn
 * into preserving a mis-attributed argument: this is the false positive #1395 was filed for.
 */
function resolvedReceiverPositionMismatchIsStripped(Sink $sink): void
{
    $sink->report(label: tainted());
}

/**
 * A `new UnresolvedSink()` receiver has no `vars_in_scope` entry, so the declared parameter
 * cannot be looked up at all and the argument is stripped even though `label:` genuinely does
 * match position 0. Accepted false negative, tracked in #1406.
 */
function unresolvedReceiverIsStripped(): void
{
    (new UnresolvedSink())->report(label: tainted());
}

/**
 * A dynamic callee has no `Name` node to resolve, so no candidate id exists. Not a
 * discriminating assertion on its own (vanilla is silent here too); it pins the shape.
 */
function dynamicCalleeIsStripped(): void
{
    $fn = 'file_put_contents';
    $fn(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
