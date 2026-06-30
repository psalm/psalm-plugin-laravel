--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #802 — negative guard for the narrowing. `url()` / `temporaryUrl()` build
 * URL strings, not file operations, so a tainted path must NOT fire `TaintedFile`
 * even though `Storage::disk()` now returns the concrete `FilesystemAdapter`.
 *
 * The empty `--EXPECTF--` makes this load-bearing: ANY finding fails the match
 * (unlike the `%A`-prefixed positive sinks in `TaintedFileStorageFacade.phpt`,
 * which only assert a lower bound). The sibling `SafeStorageUrlNoTaint.phpt`
 * proves the same on a directly-typed adapter; this proves it through the facade
 * routing that `StorageHandler` introduces.
 */
function facadeDiskUrlIsSafe(Request $request): void
{
    $path = (string) $request->input('path');

    echo Storage::disk('public')->url($path);
    echo Storage::disk('s3')->temporaryUrl($path, new \DateTimeImmutable('+1 hour'));
}
?>
--EXPECTF--
