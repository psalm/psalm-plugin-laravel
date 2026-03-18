<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when env() is called outside the config/ directory.
 * When config is cached, env() returns null outside config files.
 */
final class NoEnvOutsideConfig extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/NoEnvOutsideConfig';

    public const ERROR_LEVEL = 1;
}
