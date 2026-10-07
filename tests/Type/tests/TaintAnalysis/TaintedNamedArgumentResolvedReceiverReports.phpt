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
 * A DI-injected receiver is a plain `$var` whose type is already in scope. `paths:` names the
 * declared parameter, so the finding reports.
 */
function resolvedReceiverReports(Filesystem $filesystem): void
{
    $filesystem->delete(paths: tainted());
}

/**
 * A union receiver is not "exactly one known class", so the handler declines to resolve the
 * callee. It has nothing to strip anyway: both members declare the sunk parameter and the value
 * genuinely reaches a file sink, so Psalm reports it.
 */
function unionReceiverReports(Writer|OtherWriter $writer): void
{
    $writer->store(path: tainted());
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
