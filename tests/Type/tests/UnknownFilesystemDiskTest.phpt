--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

/**
 * Issue #1391: findUnknownFilesystemDisks flags a literal disk name that is not a key in
 * filesystems.disks. The psalm-tester harness boots the Testbench package-mode fallback (no
 * bootstrap/app.php resolved for cwd = repo root), so StorageHandler's boot-mode gate
 * (Plugin::initUnknownFilesystemDiskHandler()) never arms the check — every case below must
 * stay silent here. The rule firing at all is proven separately by a real bootstrap-mode
 * fixture (subprocess psalm run against a project with a real bootstrap/app.php), not by this
 * phpt — see the plugin-laravel PR description for that run's output.
 */

// An unknown disk — would emit UnknownFilesystemDisk under a real bootstrap boot.
Storage::disk('s3-old');
Storage::drive('s3-old');

// Known disks (Testbench ships local, public, s3) — must never emit.
Storage::disk('local');
Storage::disk('public');
Storage::disk('s3');

// Dynamic / non-literal names — must be skipped regardless of boot mode.
function test_dynamic_disk_name(string $name): void
{
    Storage::disk($name);
}

// Enum and null names — must be skipped (not a String_ node).
enum DiskName
{
    case Local;
}

Storage::disk(DiskName::Local);
Storage::disk(null);
Storage::disk();

// Empty string literal — resolves to the default disk at runtime, must be skipped.
Storage::disk('');

// DI-injected FilesystemManager / Factory contract — same gate applies.
function test_via_filesystem_manager(FilesystemManager $manager): void
{
    $manager->disk('s3-old');
}

function test_via_factory_contract(Factory $factory): void
{
    $factory->disk('s3-old');
}
?>
--EXPECTF--
