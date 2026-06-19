--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #802 — taint regression guard. Narrowing `Storage::disk()` to the
 * concrete `FilesystemAdapter` is what lets a tainted (user-controlled) path
 * reach the adapter's `@psalm-taint-sink file` methods through the facade and the
 * DI `Factory` / `FilesystemManager`. The un-annotated `Filesystem` / `Cloud`
 * *contracts* carry no sinks, so before this narrowing these path-traversal flows
 * were silently missed. The sibling `Tainted*FilesystemAdapter.phpt` tests type
 * the receiver directly and would stay green even if `StorageHandler` were
 * reverted; these assertions exercise the `disk()` routing itself.
 *
 * The `(string)` cast keeps the value tainted while giving it a concrete type, so
 * the sink fires without `MixedArgument` noise. (A `@var string` re-type would
 * strip the taint.)
 *
 * The safe counterpart — `url()` must NOT taint through the same facade path —
 * lives in `SafeStorageFacadeUrlNoTaint.phpt`, which uses an empty `--EXPECTF--`
 * so any spurious finding fails. (A negative cannot be asserted here: the
 * `%A`-prefixed positive lines below are a lower bound — an extra `TaintedFile`
 * would be absorbed by a `%A` segment, not rejected.)
 */

function facadeDiskPutIsTainted(Request $request): void
{
    $path = (string) $request->input('path');

    Storage::disk('s3')->put($path, 'contents');
}

function factoryContractDiskGetIsTainted(Request $request, Factory $factory): void
{
    $path = (string) $request->input('path');

    $factory->disk('s3')->get($path);
}

function managerDiskDeleteIsTainted(Request $request, FilesystemManager $manager): void
{
    $path = (string) $request->input('path');

    $manager->disk('s3')->delete($path);
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
