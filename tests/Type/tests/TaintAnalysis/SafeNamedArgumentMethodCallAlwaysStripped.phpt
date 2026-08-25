--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentMethodCallAlwaysStripped;

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
 * A `MethodCall` receiver's type is unresolved at NamedArgumentTaintHandler's pre-pass (it
 * fires before the receiver is descended into), so it cannot look up the declared parameter
 * to prove `label:` matches its own offset — unlike the FuncCall/StaticCall/New_ cases, EVERY
 * named argument on a MethodCall is stripped unconditionally, even here where the name
 * genuinely does match position 0. This is the accepted false negative documented in the
 * handler's class docblock.
 */
function methodCallNamedArgIsAlwaysStripped(): void
{
    (new Sink())->report(label: tainted());
}
?>
--EXPECTF--
