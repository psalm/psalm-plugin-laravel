<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when __() or trans() references a translation key
 * that does not exist in the application's language files.
 */
final class MissingTranslation extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingTranslation/';

    public const ERROR_LEVEL = 1;
}
