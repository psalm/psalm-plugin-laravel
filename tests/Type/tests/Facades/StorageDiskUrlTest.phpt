--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #973: `Storage::disk('s3')->url('x')` triggered `UndefinedInterfaceMethod`
 * because Laravel declares `disk()` as returning `Filesystem`, but `url()`
 * lives on the `Cloud extends Filesystem` sub-contract.
 *
 * Larastan's fix narrows every `disk()` call to `\Illuminate\Filesystem\FilesystemAdapter`,
 * which silently allows `disk('local')->url(...)` even though the file-only
 * `Filesystem` contract does NOT declare `url()`. We instead read
 * `config/filesystems.php`: cloud-driver disks (currently `'s3'`) narrow to
 * `Cloud`, file-only disks (`local`, `ftp`, `sftp`, `scoped`) keep the
 * contract-correct `Filesystem` return type.
 *
 * The Testbench skeleton's `config/filesystems.php` ships `local` (default),
 * `public` (driver=local), and `s3` (driver=s3) — those three drive the
 * assertions below.
 */
function test_storage_disk_s3_returns_cloud_contract(): string
{
    /** @psalm-check-type-exact $disk = Cloud */
    $disk = Storage::disk('s3');

    return $disk->url('foo.png');
}

function test_storage_drive_alias_also_narrows(): string
{
    /** @psalm-check-type-exact $disk = Cloud */
    $disk = Storage::drive('s3');

    return $disk->url('foo.png');
}

/**
 * File-only drivers stay on the base `Filesystem` contract. `url()` is then
 * correctly reported as undefined — this matches the Laravel contract design
 * (only `Cloud` declares `url()`). Users who want `url()` on a `local` disk
 * should call it on `Storage::cloud()` or against a `Cloud`-typed binding.
 */
function test_storage_disk_local_stays_file_only(): void
{
    /** @psalm-check-type-exact $disk = Filesystem */
    $disk = Storage::disk('local');

    $disk->url('foo.png');
}

/**
 * No-arg `disk()` and literal `disk(null)` both resolve to the configured
 * default disk (`filesystems.default`), which is `'local'` in the Testbench
 * skeleton — mirrors `FilesystemManager::disk()`'s `enum_value($name) ?: $this->getDefaultDriver()`.
 */
function test_storage_disk_default_resolves_via_config(): void
{
    /** @psalm-check-type-exact $disk = Filesystem */
    $disk = Storage::disk();

    $disk->url('foo.png');
}

function test_storage_disk_literal_null_resolves_via_config(): void
{
    /** @psalm-check-type-exact $disk = Filesystem */
    $disk = Storage::disk(null);

    $disk->url('foo.png');
}

/**
 * Dynamic disk names are opaque at analysis time — narrowing must NOT apply.
 * The receiver stays on the declared `Filesystem` contract so that latent
 * runtime errors (`url()` on a `local` disk) are still surfaced statically.
 */
function test_storage_disk_dynamic_name_does_not_narrow(string $name): Filesystem
{
    /** @psalm-check-type-exact $disk = Filesystem */
    $disk = Storage::disk($name);

    return $disk;
}

/**
 * Disks not declared in `filesystems.disks` fall through with no narrowing —
 * the declared `Filesystem` contract from Laravel's `@method` catalogue stands.
 * Pins the `getDriverForDisk(...) === null` branch in `StorageHandler` so a
 * future change to "default unknown disks to Cloud" would fail this test.
 */
function test_storage_disk_unknown_name_falls_through(): void
{
    /** @psalm-check-type-exact $disk = Filesystem */
    $disk = Storage::disk('disk-that-is-not-in-filesystems-config');

    $disk->url('foo.png');
}

/**
 * The facade-only `getMethodParams()` override pins `disk()`'s parameter
 * signature to `\UnitEnum|string|null`. An incompatible literal must surface
 * as `InvalidArgument`; if the override silently regressed (e.g. dropped the
 * params provider entirely), the call below would type-check silently.
 */
function test_storage_disk_rejects_non_string_non_enum_non_null_argument(): void
{
    Storage::disk(42);
}

/**
 * Non-facade receivers: DI-injected `FilesystemManager` (the concrete) and
 * `Factory` (the contract). The handler registers for both via
 * `getClassLikeNames()`, so the same narrowing must apply through DI without
 * regressing Psalm's native resolution of `disk()`'s params (`disk()` is a real
 * method on both classes — see `getMethodParams()`'s facade-only carve-out).
 */
function test_filesystem_manager_disk_narrows(FilesystemManager $mgr): string
{
    /** @psalm-check-type-exact $disk = Cloud */
    $disk = $mgr->disk('s3');

    return $disk->url('foo.png');
}

function test_factory_contract_disk_narrows(Factory $factory): string
{
    /** @psalm-check-type-exact $disk = Cloud */
    $disk = $factory->disk('s3');

    return $disk->url('foo.png');
}

?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::url does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::url does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::url does not exist
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Filesystem\Filesystem::url does not exist
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Facades\Storage::disk expects UnitEnum|null|string, but 42 provided
