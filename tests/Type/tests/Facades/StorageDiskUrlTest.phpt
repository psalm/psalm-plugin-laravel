--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/**
 * Reproducer (documented broken behavior): `Storage::disk($name)->url($path)`.
 *
 * From invoiceninja's app/Console/Commands/BackupUpdate.php — three occurrences
 * across that file alone:
 *
 *   $url = Storage::disk($this->option('disk'))->url($path);
 *
 * The `Storage` facade's `@method static disk(...)` is declared by Laravel to
 * return `\Illuminate\Contracts\Filesystem\Filesystem`. The `url($path)` method
 * lives on the `Cloud` sub-contract (and on the concrete `FilesystemAdapter`),
 * not on `Filesystem`. So Psalm correctly reports `UndefinedInterfaceMethod`
 * even though every disk driver Laravel ships extends `FilesystemAdapter` and
 * therefore implements `url()` at runtime.
 *
 * Fix vector: override the `Storage::disk` return type to `FilesystemAdapter`
 * in a plugin stub (mirroring Larastan's approach), or add `url()` to the
 * `Filesystem` contract stub. Either is a small, focused change but kept out of
 * this PR to scope it to test additions.
 */
function test_storage_disk_url_undefined_on_filesystem_contract(): void
{
    Storage::disk('local')->url('foo.png');
}

?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::url does not exist
