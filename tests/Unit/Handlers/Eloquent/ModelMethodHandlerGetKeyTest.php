<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TableSchema;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TraitFlags;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;

#[CoversClass(ModelMethodHandler::class)]
final class ModelMethodHandlerGetKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
    }

    #[Test]
    public function incomplete_primary_key_metadata_does_not_narrow_get_key(): void
    {
        $metadata = new ModelMetadata(
            fqcn: StubModelForGetKeyMetadata::class,
            primaryKey: new PrimaryKeyInfo('id', PrimaryKeyType::String, incrementing: false, uuidColumns: []),
            traits: new TraitFlags(
                hasSoftDeletes: false,
                hasUuids: false,
                hasUlids: false,
                hasFactory: false,
                hasApiTokens: false,
                hasNotifications: false,
                hasGlobalScopes: false,
                usesTimestamps: true,
            ),
            fillable: [],
            guarded: [],
            appends: [],
            with: [],
            withCount: [],
            hidden: [],
            visible: [],
            connection: null,
            morphAlias: null,
            customBuilder: null,
            customCollection: null,
            schemaData: new TableSchema([]),
            castsData: [],
            accessorsData: [],
            mutatorsData: [],
            scopesData: [],
            relationsData: [],
            knownPropertiesData: [],
            completeSections: ModelMetadata::ALL_SECTIONS & ~ModelMetadata::SECTION_PRIMARY_KEY,
        );

        ModelMetadataRegistryBuilder::overrideForTesting(StubModelForGetKeyMetadata::class, $metadata);
        $registeredMetadata = ModelMetadataRegistry::for(StubModelForGetKeyMetadata::class);
        $method = new \ReflectionMethod(ModelMethodHandler::class, 'getKeyReturnTypeFromMetadata');

        $this->assertFalse($metadata->isComplete(ModelMetadata::SECTION_PRIMARY_KEY));
        $this->assertSame($metadata, $registeredMetadata);
        $this->assertNull($method->invoke(null, $registeredMetadata));
    }
}

/** @internal */
final class StubModelForGetKeyMetadata extends Model {}
