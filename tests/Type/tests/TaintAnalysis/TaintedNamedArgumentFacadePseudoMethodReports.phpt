--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentFacadePseudoMethodReports;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * A facade's `@method static` tag declares params but no real MethodStorage, so
 * `Codebase::getFunctionLikeStorage()` cannot see it and `pseudoMethodParams()` is the only
 * route to its parameter names. Named arguments on facade calls must report.
 *
 * This pins the PRESERVE direction only, for the same reason as the builtin sibling: a
 * resolution failure now preserves, so breaking `pseudoMethodParams()` would not fail this file.
 * No stock facade pseudo-method pairs a variadic with a sink on a non-variadic parameter, and a
 * userland `@method` tag cannot carry `@psalm-taint-sink`, so that half has no fixture.
 */
function facadeNamedArgumentsKeepTaint(): void
{
    File::delete(paths: tainted());
    Storage::get(path: tainted());
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
TaintedFile on line %d: Detected tainted file handling
