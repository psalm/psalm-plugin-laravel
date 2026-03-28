<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Static holder for the SchemaAggregator instance.
 *
 * Static state is required by Psalm's architecture — getClassLikeNames() is static,
 * so handlers cannot receive dependencies via constructor injection.
 * Set once at plugin init, before handler registration.
 *
 * @internal
 * @psalm-external-mutation-free
 */
final class SchemaStateProvider
{
    private static ?SchemaAggregator $schema = null;

    /** @psalm-external-mutation-free */
    public static function setSchema(SchemaAggregator $schema): void
    {
        self::$schema = $schema;
    }

    /** @psalm-external-mutation-free */
    public static function getSchema(): ?SchemaAggregator
    {
        return self::$schema;
    }
}
