<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

/**
 * Controls how the plugin infers Eloquent model property types
 * when no `@property` annotation is present on the model class.
 * @internal
 */
enum ColumnFallback: string
{
    /** Parse migration files to infer column names and types. */
    case Migrations = 'migrations';

    /** Disable migration-based column inference. */
    case None = 'none';
}
