<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when Storage::disk()/drive() (or the same call on a DI-injected
 * FilesystemManager/Factory contract) is given a literal disk name that is not
 * configured in filesystems.disks.
 */
final class UnknownFilesystemDisk extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnknownFilesystemDisk/';

    // No ERROR_LEVEL override: controlled by the plugin setting findUnknownFilesystemDisks
}
