--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentResolvedReceiverReports;

use Illuminate\Filesystem\Filesystem;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Writer
{
    /** @psalm-taint-sink file $path */
    public function store(string $path): void { echo $path; }
}

final class OtherWriter
{
    /** @psalm-taint-sink file $path */
    public function store(string $path): void { echo $path; }
}

/**
 * A DI-injected receiver is a plain `$var` whose type is already in scope, so the handler
 * resolves `Filesystem::delete` and sees that `paths:` names the declared parameter at its own
 * written offset. Upstream attributes it correctly, so the finding must survive.
 */
function resolvedReceiverKeepsTaint(Filesystem $filesystem): void
{
    $filesystem->delete(paths: tainted());
}

/**
 * The negative half of the same narrowing: a union receiver is not "exactly one known class",
 * so the callee stays unresolvable and the argument is stripped. Reports nothing, even though
 * both members declare the same sunk parameter at the same offset.
 */
function unionReceiverIsStripped(Writer|OtherWriter $writer): void
{
    $writer->store(path: tainted());
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
