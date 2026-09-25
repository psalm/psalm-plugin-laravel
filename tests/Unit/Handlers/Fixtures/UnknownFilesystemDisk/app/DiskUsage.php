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

    /** `enum_value($name) ?: getDefaultDriver()` treats '0' as falsy — resolves the default disk, must stay silent. */
    public function readsFromTheDefaultDiskViaFalsyName(): string
    {
        return Storage::disk('0')->get('report.csv');
    }

    /** Dotted names resolve nested config groups via Laravel's dotted lookup — must stay silent. */
    public function readsFromANestedDisk(): string
    {
        return Storage::disk('tenant.assets')->get('report.csv');
    }

    /**
     * A DI-injected manager may be a userland subclass with its own disk resolution
     * (e.g. an overridden `getConfig()`), so the diagnostic is facade-only — even an
     * unknown literal on a manager receiver must stay silent.
     */
    public function readsViaAnInjectedManager(\Illuminate\Filesystem\FilesystemManager $manager): string
    {
        return $manager->disk('not-configured-anywhere')->get('report.csv');
    }
}
