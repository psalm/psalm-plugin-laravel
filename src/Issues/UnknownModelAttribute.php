<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a key in an array passed to a mass-assignment method (`create()` / `fill()` /
 * `update()` and their `forceCreate` / `forceFill` / `Quietly` / `updateOrFail` variants) is not a
 * known attribute of the receiving Eloquent model — typically a typo like `['nmae' => ...]`. The
 * recognized set unions the model's columns, casts, accessors, mutators, relations, `$appends`,
 * `$fillable`, and `@property*` docblocks; the check is skipped when the column schema is unknown
 * (migrations off), so it stays silent rather than risk false positives.
 */
final class UnknownModelAttribute extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnknownModelAttribute/';
}
