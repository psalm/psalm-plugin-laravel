<?php

declare(strict_types=1);

// Namespaced so `Disk::Public` must resolve through the namespace, not the bare short name.

namespace App;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

// config/filesystems.php configures: local, public, and a `tenant` group of nested disks.

function configured_disk(): void
{
    Storage::disk('local');
}

function unconfigured_literal(): void
{
    Storage::disk('s3-old'); // flagged
    Storage::drive('s3-old'); // flagged
}

function unconfigured_via_root_alias(): void
{
    \Storage::disk('missing-alias'); // flagged
}

function typo_gets_a_suggestion(): void
{
    Storage::disk('publik'); // flagged, "did you mean 'public'?"
}

/** A group without a `driver` is not a disk: `disk('tenant')` throws at runtime. */
function nested_group_is_not_a_disk(): void
{
    Storage::disk('tenant'); // flagged
}

enum Disk: string
{
    case Public = 'public';
    case Legacy = 'legacy';
}

enum NumberedDisk: int
{
    case First = 1;
}

final class DiskNames
{
    public const LOCAL = 'local';

    public const OLD = 'old-archive';

    public function viaSelf(): void
    {
        Storage::disk(self::OLD); // flagged
    }
}

function enum_and_constant_names(): void
{
    Storage::disk(Disk::Public);
    Storage::disk(Disk::Legacy); // flagged
    Storage::disk(DiskNames::LOCAL);
    Storage::disk(DiskNames::OLD); // flagged
}

// The cases below must stay silent.

function declined_names(string $name, bool $flag): void
{
    Storage::disk($name);
    Storage::disk($flag ? 's3-old' : 'local'); // more than one possible name
    Storage::disk(NumberedDisk::First); // int-backed: not a disk name
    Storage::disk();
    Storage::disk(''); // falsy names resolve the default disk
    Storage::disk('0');
    Storage::disk('tenant.assets'); // dotted names reach nested config groups
}

/** A DI receiver may be a userland subclass with its own disk resolution. */
function injected_manager(FilesystemManager $manager): void
{
    $manager->disk('missing-manager');
}
