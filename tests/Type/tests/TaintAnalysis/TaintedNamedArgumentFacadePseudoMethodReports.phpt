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
 * `Codebase::getFunctionLikeStorage()` cannot see it and the callee used to count as
 * "unresolvable" — stripping every named argument on every facade call. Both names below
 * match the declared parameter at their own written offset, so upstream attributes them
 * correctly and the findings must survive.
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
