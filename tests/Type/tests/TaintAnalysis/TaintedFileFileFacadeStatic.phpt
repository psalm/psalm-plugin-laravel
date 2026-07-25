--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * `File::delete()` forwards to `Illuminate\Filesystem\Filesystem::delete()`, whose
 * `$paths` is a file sink. The facade static form only reaches it because
 * FacadeTaintForwardingHandler copies the sink onto the `@method` pseudo-method.
 */

function facadeStaticDeleteIsTainted(Request $request): void
{
    File::delete((string) $request->input('path'));
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
