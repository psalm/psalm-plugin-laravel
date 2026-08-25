--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentUnresolvedReceiverStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Sink
{
    /** @psalm-taint-sink html $label */
    public function report(string $label = 'x'): void
    {
        echo $label;
    }
}

/**
 * NamedArgumentTaintHandler resolves a method call's receiver only from a plain `$var` already
 * in scope. A `new Sink()` receiver has no such entry, so the declared parameter cannot be
 * looked up and the argument is stripped even though `label:` genuinely does match position 0 —
 * the accepted false negative documented on the handler and in issue #1406.
 */
function methodCallNamedArgOnUnresolvedReceiverIsStripped(): void
{
    (new Sink())->report(label: tainted());
}
?>
--EXPECTF--
