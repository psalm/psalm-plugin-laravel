--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentPositionMismatchStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * Upstream (vimeo/psalm#11923) attributes a named argument's taint node to the parameter at
 * its WRITTEN offset rather than the one it names. `label: tainted()` is written at offset 0,
 * but the declared parameter at offset 0 is `$path`, not `$label` — a mismatch, so
 * NamedArgumentTaintHandler must strip ALL taint from this argument value rather than let it
 * mis-report on `$path` (a false positive) or correctly report on `$label` (lost as an
 * accepted false negative — see the handler's class docblock).
 *
 * @psalm-taint-sink file $path
 * @psalm-taint-sink html $label
 */
function sink(string $path = 'safe', string $label = 'x'): void
{
    echo $path;
    echo $label;
}

function positionMismatchStripsAllTaint(): void
{
    sink(label: tainted());
}
?>
--EXPECTF--
