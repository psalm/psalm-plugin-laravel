--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #802: `Storage::disk()` narrows to the concrete
 * `Illuminate\Filesystem\FilesystemAdapter` for every disk. This exposes the
 * adapter-only methods `temporaryUrl()` / `temporaryUploadUrl()` (declared on no
 * contract) and `url()` (declared on `Cloud`, not the base `Filesystem`) — none
 * reachable through Laravel's declared `Filesystem` return type. (Stream and
 * visibility methods like `readStream()` are already on the `Filesystem`
 * contract; they are not what this narrowing is for.)
 *
 * This reverses #973/#982 (which narrowed `s3` to `Cloud` and kept file-only
 * disks on the bare `Filesystem` contract). See `StorageHandler` for the
 * rationale and the accepted tradeoff.
 *
 * The Testbench skeleton's `config/filesystems.php` ships `local` (default),
 * `public` (driver=local), and `s3` (driver=s3).
 */

// --- Every disk shape narrows to the same concrete adapter ------------------

function test_disk_s3_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk('s3');

    return $disk;
}

function test_disk_local_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk('local');

    return $disk;
}

function test_disk_public_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk('public');

    return $disk;
}

function test_disk_no_arg_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk();

    return $disk;
}

function test_disk_literal_null_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk(null);

    return $disk;
}

/**
 * Unlike #982, a dynamic disk name now narrows too — the runtime class does not
 * depend on the name, so there is no reason to fall back to the contract.
 */
function test_disk_dynamic_name_returns_adapter(string $name): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk($name);

    return $disk;
}

function test_disk_unknown_name_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::disk('disk-that-is-not-in-filesystems-config');

    return $disk;
}

function test_drive_alias_returns_adapter(): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = Storage::drive('s3');

    return $disk;
}

// --- The adapter surface that #802 is about is now reachable ----------------

/** `temporaryUrl()` exists only on `FilesystemAdapter` (the #802 headline). */
function test_temporary_url_is_reachable(): string
{
    return Storage::disk('s3')->temporaryUrl('foo.mp3', new \DateTime('+1 hour'));
}

/** Previously a false positive under #982 — `public` uses `driver=local`. */
function test_url_on_public_disk_is_reachable(): string
{
    return Storage::disk('public')->url('avatar.png');
}

/** `temporaryUploadUrl()` is adapter-only too — absent from both contracts. */
function test_temporary_upload_url_is_reachable(): array
{
    return Storage::disk('s3')->temporaryUploadUrl('foo.mp3', new \DateTime('+1 hour'));
}

// --- DI-injected receivers narrow identically -------------------------------

function test_manager_disk_returns_adapter(FilesystemManager $mgr): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = $mgr->disk('s3');

    return $disk;
}

function test_factory_contract_disk_returns_adapter(Factory $factory): FilesystemAdapter
{
    /** @psalm-check-type-exact $disk = FilesystemAdapter */
    $disk = $factory->disk('s3');

    return $disk;
}

// --- Intentional diagnostics kept -------------------------------------------

/**
 * The facade-only `getMethodParams()` override pins `disk()`'s parameter
 * signature to `\UnitEnum|string|null`, so a non-string/enum/null argument is
 * still rejected (guards against the params provider silently regressing).
 */
function test_disk_rejects_non_string_non_enum_argument(): void
{
    Storage::disk(42);
}

/**
 * `put($path, fopen(...))` stays a (correct) `PossiblyFalseArgument`: `fopen()`
 * returns `resource|false` and `put()`'s `$contents` does not accept `false`.
 * Narrowing to the adapter does not suppress this — callers must guard `fopen()`.
 */
function test_put_with_unchecked_fopen_is_flagged(): void
{
    Storage::disk('s3')->put('foo.mp3', fopen('/tmp/foo', 'r'));
}

/** The guarded form is accepted — no diagnostic. */
function test_put_with_checked_resource_is_accepted(): void
{
    $handle = fopen('/tmp/foo', 'r');
    if ($handle === false) {
        return;
    }

    Storage::disk('s3')->put('foo.mp3', $handle);
}

?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Facades\Storage::disk expects UnitEnum|null|string, but 42 provided
PossiblyFalseArgument on line %d: Argument 2 of Illuminate\Filesystem\FilesystemAdapter::put cannot be false, possibly %s value expected
