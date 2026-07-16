<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Two ways to cast a column to a `Collection`: the legacy bare string (a primitive per Laravel's
 * `HasAttributes::$primitiveCastTypes`) and the modern `AsCollection::class` Castable wrapper (class-
 * castable per `Model::isClassCastable()`). Exercises `ModelMetadataRegistryBuilder::classifyCast()`'s
 * shape classification for both forms — see
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata\ModelMetadataRegistryTest}.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class CollectionCastVariantsModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'legacy_tags' => 'collection',
        'modern_tags' => AsCollection::class,
    ];
}
