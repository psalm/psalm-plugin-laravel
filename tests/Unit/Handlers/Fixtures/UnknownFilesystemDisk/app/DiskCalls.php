<?php

declare(strict_types=1);

namespace UnknownDiskFixture;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

enum Disk: string
{
    case Archive = 'archive';
    case Legacy = 'archive-legacy';
}

enum PureDisk
{
    case archive;
    case backups;
}

enum IntDisk: int
{
    case First = 1;
}

final class DiskNames
{
    public const ARCHIVE = 'archive';

    public const OLD = 's3-old';

    public const PREFIXED = 'arch' . 'ive-copy';

    public function viaSelf(): void
    {
        Storage::disk(self::ARCHIVE);
        Storage::disk(self::OLD); // flagged: 's3-old'
    }

    public function viaStatic(): void
    {
        // Late static binding: a subclass may redefine the constant — declined.
        Storage::disk(static::OLD);
    }
}

final class DiskCalls
{
    public function literals(FilesystemManager $manager): void
    {
        Storage::disk('archive');
        Storage::disk('missing-literal'); // flagged
        \Storage::disk('missing-alias'); // flagged: root alias
        $manager->disk('missing-manager'); // flagged: DI receiver
    }

    public function enums(): void
    {
        Storage::disk(Disk::Archive);
        Storage::disk(Disk::Legacy); // flagged: 'archive-legacy'
        Storage::disk(PureDisk::archive);
        Storage::disk(PureDisk::backups); // flagged: enum_value() yields the case name
        \Storage::drive(Disk::Legacy); // flagged: alias + enum
        Storage::disk(IntDisk::First); // declined: int-backed
    }

    public function constants(): void
    {
        Storage::disk(DiskNames::ARCHIVE);
        Storage::disk(DiskNames::OLD); // flagged: 's3-old'
        Storage::disk(DiskNames::PREFIXED); // flagged: constant expression 'archive-copy'
    }

    public function dynamic(string $name): void
    {
        Storage::disk($name);
        Storage::disk();
        Storage::disk(null);
        Storage::disk('');
    }
}
