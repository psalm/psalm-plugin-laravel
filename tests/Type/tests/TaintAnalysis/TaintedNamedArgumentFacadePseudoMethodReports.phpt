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
 * A facade's `@method static` tag declares params but no real MethodStorage, so the handler
 * cannot resolve the callee and leaves it to Psalm. Named arguments on facade calls must
 * keep reporting.
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
