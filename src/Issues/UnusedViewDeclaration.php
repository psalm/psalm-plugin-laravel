<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Issues;

use Psalm\Issue\PluginIssue;

/**
 * Reported, once, at a Blade template's `@var` declaration when neither the template nor any template
 * it hands its whole scope to (`@include`, `@extends`) ever reads the variable.
 */
final class UnusedViewDeclaration extends PluginIssue
{
    public const DOCUMENTATION_URL = 'https://psalm.github.io/psalm-plugin-laravel/issues/UnusedViewDeclaration/';

    // Level 6 like MissingViewData: this report replaces that one for the same declaration, so the stale
    // `@var` keeps the level its omission was reported at.
    public const ERROR_LEVEL = 6;
}
