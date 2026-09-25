<?php

declare(strict_types=1);

namespace UnknownDiskFixture;

use Illuminate\Support\Facades\Storage;

final class DiskUsage
{
    /** Not a key in config/filesystems.php's `disks` — must emit UnknownFilesystemDisk. */
    public function readsFromAnUnconfiguredDisk(): string
    {
        return Storage::disk('s3-old')->get('report.csv');
    }

    /** A disk actually present in config/filesystems.php — must stay silent. */
    public function readsFromAConfiguredDisk(): string
    {
        return Storage::disk('local')->get('report.csv');
    }
}
