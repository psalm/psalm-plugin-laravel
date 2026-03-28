<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported when Model::make() is used instead of new Model().
 * The constructor is clearer and avoids __callStatic indirection.
 */
final class ModelMakeDiscouraged extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/ModelMakeDiscouraged/';
}
