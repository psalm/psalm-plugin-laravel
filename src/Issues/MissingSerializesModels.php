<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when a class implementing ShouldQueue holds an Eloquent model in a property
 * but cannot reach Illuminate\Queue\SerializesModels, so the whole model is written into
 * the queue payload instead of a ModelIdentifier.
 */
final class MissingSerializesModels extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/MissingSerializesModels/';
}
