--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentResolvedReceiverReports;

use Illuminate\Filesystem\Filesystem;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * A DI-injected receiver is a plain `$var` whose type is already in scope, so the handler can
 * resolve `Filesystem::delete` and see that `paths:` names the declared parameter at its own
 * written offset. Upstream attributes it correctly, so the finding must survive.
 */
function resolvedReceiverKeepsTaint(Filesystem $filesystem): void
{
    $filesystem->delete(paths: tainted());
}

/**
 * A union receiver is not "exactly one known class", so the callee stays unresolvable and the
 * argument is stripped — the safe direction, and the negative half of the same narrowing.
 */
function unionReceiverIsStripped(Filesystem|null $filesystem): void
{
    $filesystem?->delete(paths: tainted());
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
