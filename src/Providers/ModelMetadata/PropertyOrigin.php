<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Origin of a key in `ModelMetadata::knownProperties()`.
 *
 * A single property may originate in multiple places (e.g. a schema column
 * that also has an accessor); {@see PropertyOrigins} aggregates the set.
 *
 * Consumed primarily by the future `#699` unknown-key detector —
 * the full computation is deferred to Phase 3.
 *
 * @internal
 */
enum PropertyOrigin: string
{
    case SchemaColumn = 'schema_column';
    case Cast = 'cast';
    case Accessor = 'accessor';
    case Mutator = 'mutator';
    case Relation = 'relation';
    case Appended = 'appended';
    case Docblock = 'docblock';
}
