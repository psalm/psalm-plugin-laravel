--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentFrameworkCalleeReports;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * Callees without a userland `FunctionStorage` (a PHP builtin, facade `@method` pseudo-methods, a
 * DI-injected `$var` receiver, a literal-string callee) are left to Psalm, which keys the named
 * argument by its declared parameter: each must keep reporting the vendor `file` sink.
 */
function frameworkCallees(Filesystem $filesystem): void
{
    file_put_contents(filename: tainted(), data: 'x');
    File::delete(paths: tainted());
    Storage::get(path: tainted());
    $filesystem->delete(paths: tainted());

    $fn = 'file_put_contents';
    $fn(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
TaintedFile on line %d: Detected tainted file handling
TaintedFile on line %d: Detected tainted file handling
TaintedFile on line %d: Detected tainted file handling
TaintedFile on line %d: Detected tainted file handling
