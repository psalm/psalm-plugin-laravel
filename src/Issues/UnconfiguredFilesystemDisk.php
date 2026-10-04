<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when Storage::disk()/drive() (or the same call on the root \Storage
 * alias) is given a statically known disk name that is not configured in
 * filesystems.disks.
 */
final class UnconfiguredFilesystemDisk extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnconfiguredFilesystemDisk/';

    // No ERROR_LEVEL override: controlled by the plugin setting findUnconfiguredFilesystemDisks
}
