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
 * A union receiver is not "exactly one known class", so `resolveReceiverClass()` declines and the
 * callee's declared parameters stay unreachable. That is no longer a reason to strip: upstream
 * keys the argument node by the declared index of the parameter `path:` names, in both union
 * members, so both findings must survive. The narrowing only decides whether a variadic capture
 * can be PROVEN ({@see SafeNamedArgumentVariadicCaptureReceiverAndConstructorStripped.phpt});
 * failing to prove one preserves.
 */
function unionReceiverKeepsTaint(Writer|OtherWriter $writer): void
{
    $writer->store(path: tainted());
}

/**
 * A DI-injected receiver is a plain `$var` whose type is already in scope, so the handler
 * resolves `Filesystem::delete` and reads its declared parameters. `paths:` names one of them, so
 * nothing is captured by a variadic and the vendor `file` sink reports.
 */
function resolvedReceiverKeepsTaint(Filesystem $filesystem): void
{
    $filesystem->delete(paths: tainted());
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedFile on line %d: Detected tainted file handling
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedFile on line %d: Detected tainted file handling
