<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when view() or Factory::make() references a Blade template
 * that does not exist on disk.
 */
final class MissingView extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingView/';

    public const ERROR_LEVEL = 1;
}
