<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use App\Builders\WorkOrderBuilder;
use App\Collections\WorkOrderCollection;
use App\Models\AbstractDocument;
use App\Models\Customer;
use App\Models\CustomPkUuidModel;
use App\Models\SpecializationPivot;
use App\Models\UlidModel;
use App\Models\UuidModel;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastShape;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TableSchema;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TraitFlags;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\ClassLikeStorage;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CustomDeletedAtModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\InboundCastModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;

#[CoversClass(ModelMetadataRegistry::class)]
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[CoversClass(ModelMetadata::class)]
final class ModelMetadataRegistryTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    #[\Override]
    protected function setUp(): void
    {
        ModelMetadataRegistryBuilder::reset();
        SchemaStateProvider::setSchema(new SchemaAggregator());
        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
    }

    // ---------------------------------------------------------------------
    // Lookup / cache semantics
    // ---------------------------------------------------------------------

    #[Test]
    public function for_returns_null_when_class_is_not_warmed_up(): void
    {
        $this->assertNull(ModelMetadataRegistry::for(WorkOrder::class));
    }

    #[Test]
    public function for_returns_null_for_non_model_class(): void
    {
        $codebase = $this->makeCodebase();
        ModelMetadataRegistryBuilder::warmUp($codebase, \stdClass::class);

        $this->assertNull(ModelMetadataRegistry::for(\stdClass::class));
    }

    #[Test]
    public function for_returns_null_for_unloadable_class(): void
    {
        $codebase = $this->makeCodebase();
        ModelMetadataRegistryBuilder::warmUp($codebase, 'NonExistent\\Model');

        $this->assertNull(ModelMetadataRegistry::for('NonExistent\\Model'));
    }

    #[Test]
    public function abstract_base_yields_metadata_without_instantiating(): void
    {
        // Registry twin of #1058's AbstractModelScopeResolutionTest: an abstract base cannot be
        // instantiated, but its storage/reflection-derived metadata must still resolve so future
        // phases can answer scope/property queries on an abstract-typed receiver (#901).
        // AbstractDocument declares a cast and an accessor; the cast is an INSTANCE-derived field,
        // so it must come back EMPTY here — proving warmUp() never instantiated the abstract class.
        $codebase = $this->makeCodebase();
        $this->registerStorage(AbstractDocument::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AbstractDocument::class);

        $metadata = ModelMetadataRegistry::for(AbstractDocument::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        // Instance-derived fields are empty for an abstract base (no instance, no table).
        $this->assertSame([], $metadata->schema()->all());
        $this->assertSame([], $metadata->casts());
        $this->assertNull($metadata->connection);

        // Storage/default-derived fields still populate (read from declared property defaults).
        $this->assertSame('id', $metadata->primaryKey->name);
        $this->assertSame(PrimaryKeyType::Integer, $metadata->primaryKey->type);
        // $guarded proves the abstract-only defaults-read path (computeForAbstract): base Model
        // defaults it to ['*'], whereas a broken/empty getDefaultProperties() read would fall back
        // to [] (stringListDefault). usesTimestamps is asserted for completeness but is non-
        // discriminating here — its asBool() fallback is also `true`, so it can't fail this case.
        $this->assertSame(['*'], $metadata->guarded);
        $this->assertTrue($metadata->traits->usesTimestamps);
    }

    #[Test]
    public function override_for_testing_bypasses_compute(): void
    {
        $metadata = $this->makeStubMetadata(WorkOrder::class);

        ModelMetadataRegistryBuilder::overrideForTesting(WorkOrder::class, $metadata);

        $this->assertSame($metadata, ModelMetadataRegistry::for(WorkOrder::class));
    }

    #[Test]
    public function for_is_idempotent_across_repeated_calls(): void
    {
        $metadata = $this->makeStubMetadata(WorkOrder::class);
        ModelMetadataRegistryBuilder::overrideForTesting(WorkOrder::class, $metadata);

        $this->assertSame(ModelMetadataRegistry::for(WorkOrder::class), ModelMetadataRegistry::for(WorkOrder::class));
    }

    #[Test]
    public function reset_clears_cached_metadata(): void
    {
        ModelMetadataRegistryBuilder::overrideForTesting(
            WorkOrder::class,
            $this->makeStubMetadata(WorkOrder::class),
        );

        ModelMetadataRegistryBuilder::reset();

        $this->assertNull(ModelMetadataRegistry::for(WorkOrder::class));
    }

    #[Test]
    public function all_iterates_every_warmed_entry(): void
    {
        ModelMetadataRegistryBuilder::overrideForTesting(
            WorkOrder::class,
            $this->makeStubMetadata(WorkOrder::class),
        );
        ModelMetadataRegistryBuilder::overrideForTesting(
            UuidModel::class,
            $this->makeStubMetadata(UuidModel::class),
        );

        $keys = [];
        foreach (ModelMetadataRegistry::all() as $fqcn => $_) {
            $keys[] = $fqcn;
        }

        $this->assertContains(WorkOrder::class, $keys);
        $this->assertContains(UuidModel::class, $keys);
    }

    #[Test]
    public function init_captures_progress_handle(): void
    {
        $progress = new VoidProgress();
        ModelMetadataRegistry::init($progress);

        $this->assertSame($progress, ModelMetadataRegistry::getProgress());
    }

    // ---------------------------------------------------------------------
    // Compute: warmUp idempotency
    // ---------------------------------------------------------------------

    #[Test]
    public function warm_up_is_idempotent(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(WorkOrder::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);
        $first = ModelMetadataRegistry::for(WorkOrder::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);
        $second = ModelMetadataRegistry::for(WorkOrder::class);

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $first);
        $this->assertSame($first, $second);
    }

    // ---------------------------------------------------------------------
    // Fixture: trait-derived metadata
    // ---------------------------------------------------------------------

    #[Test]
    public function has_uuids_trait_yields_string_primary_key(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(UuidModel::class, [HasUuids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, UuidModel::class);

        $metadata = ModelMetadataRegistry::for(UuidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasUuids);
        $this->assertSame(PrimaryKeyType::String, $metadata->primaryKey->type);
        $this->assertFalse($metadata->primaryKey->incrementing);
        $this->assertSame(['id'], $metadata->primaryKey->uuidColumns);
    }

    #[Test]
    public function has_uuids_does_not_inject_bogus_int_cast_on_primary_key(): void
    {
        // Regression: before the $usesUniqueIds reflective flip, $instance->getCasts()
        // returned [id => 'int'] for HasUuids models because getIncrementing() fell through
        // to the Model default. The registry must not reflect that bogus entry.
        $codebase = $this->makeCodebase();
        $this->registerStorage(UuidModel::class, [HasUuids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, UuidModel::class);

        $metadata = ModelMetadataRegistry::for(UuidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        if (isset($casts['id'])) {
            $this->fail(
                "HasUuids model 'id' column should not carry an auto-injected cast. "
                . "Got shape: {$casts['id']->shape->value}.",
            );
        }
    }

    #[Test]
    public function soft_deletes_honors_deleted_at_class_constant_override(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(CustomDeletedAtModel::class, [SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, CustomDeletedAtModel::class);

        $metadata = ModelMetadataRegistry::for(CustomDeletedAtModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        $this->assertArrayHasKey('archived_at', $casts);
        $this->assertSame(CastShape::DateTime, $casts['archived_at']->shape);
        // And the default 'deleted_at' key must not appear for this model.
        $this->assertArrayNotHasKey('deleted_at', $casts);
    }

    #[Test]
    public function has_ulids_trait_yields_string_primary_key(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(UlidModel::class, [HasUlids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, UlidModel::class);

        $metadata = ModelMetadataRegistry::for(UlidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasUlids);
        $this->assertSame(PrimaryKeyType::String, $metadata->primaryKey->type);
        $this->assertFalse($metadata->primaryKey->incrementing);
        // Same bogus-cast guard as the HasUuids test: flipUsesUniqueIds keeps
        // getCasts() from injecting [id => 'int'] on ULID models too.
        $this->assertArrayNotHasKey('id', $metadata->casts());
    }

    #[Test]
    public function soft_deletes_trait_adds_deleted_at_cast(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(Customer::class, [SoftDeletes::class, HasFactory::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $metadata = ModelMetadataRegistry::for(Customer::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasSoftDeletes);
        $this->assertArrayHasKey('deleted_at', $metadata->casts());
        $this->assertSame(CastShape::DateTime, $metadata->casts()['deleted_at']->shape);
    }

    #[Test]
    public function has_factory_trait_is_flagged(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(Customer::class, [HasFactory::class, SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $metadata = ModelMetadataRegistry::for(Customer::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasFactory);
    }

    #[Test]
    public function custom_primary_key_column_is_preserved(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(CustomPkUuidModel::class, [HasUuids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, CustomPkUuidModel::class);

        $metadata = ModelMetadataRegistry::for(CustomPkUuidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertSame('custom_pk', $metadata->primaryKey->name);
        $this->assertSame(PrimaryKeyType::String, $metadata->primaryKey->type);
        $this->assertSame(['custom_pk'], $metadata->primaryKey->uuidColumns);
    }

    // ---------------------------------------------------------------------
    // Fixture: schema + casts integration
    // ---------------------------------------------------------------------

    #[Test]
    public function schema_reflects_migration_columns(): void
    {
        $this->seedSchema('work_orders', [
            new SchemaColumn('id', SchemaColumn::TYPE_INT),
            new SchemaColumn('title', SchemaColumn::TYPE_STRING),
            new SchemaColumn('published_at', SchemaColumn::TYPE_STRING, nullable: true),
        ]);

        $codebase = $this->makeCodebase();
        $this->registerStorage(WorkOrder::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

        $metadata = ModelMetadataRegistry::for(WorkOrder::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        $schema = $metadata->schema();
        $this->assertTrue($schema->has('title'));
        $this->assertInstanceOf(ColumnInfo::class, $schema->column('published_at'));
        $this->assertTrue($schema->column('published_at')->nullable);
    }

    #[Test]
    public function pivot_model_is_tolerated(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(SpecializationPivot::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, SpecializationPivot::class);

        $metadata = ModelMetadataRegistry::for(SpecializationPivot::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
    }

    #[Test]
    public function morph_map_alias_is_resolved(): void
    {
        Relation::morphMap(['wo' => WorkOrder::class], merge: false);

        try {
            $codebase = $this->makeCodebase();
            $this->registerStorage(WorkOrder::class, [SoftDeletes::class]);

            ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

            $metadata = ModelMetadataRegistry::for(WorkOrder::class);
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
            $this->assertSame('wo', $metadata->morphAlias);
        } finally {
            Relation::morphMap([], merge: false);
        }
    }

    #[Test]
    public function morph_map_miss_yields_null_alias(): void
    {
        Relation::morphMap([], merge: false);

        try {
            $codebase = $this->makeCodebase();
            $this->registerStorage(WorkOrder::class, [SoftDeletes::class]);

            ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

            $metadata = ModelMetadataRegistry::for(WorkOrder::class);
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
            $this->assertNull($metadata->morphAlias);
        } finally {
            Relation::morphMap([], merge: false);
        }
    }

    #[Test]
    public function scalar_fields_are_read_from_model_properties(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(ScalarFieldsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, ScalarFieldsModel::class);

        $metadata = ModelMetadataRegistry::for(ScalarFieldsModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        // All attribute-name and relation-method lists preserve the exact case the
        // user declared — Eloquent's isFillable / isGuarded / getHidden / with-loading
        // use case-sensitive string comparisons at runtime.
        $this->assertSame(['Name', 'EMAIL'], $metadata->fillable);
        $this->assertSame(['Id'], $metadata->guarded);
        $this->assertSame(['Password'], $metadata->hidden);
        $this->assertSame(['FullName'], $metadata->appends);
        $this->assertSame(['primaryAuthor'], $metadata->with);
        $this->assertSame(['approvedComments'], $metadata->withCount);
        $this->assertSame('reporting', $metadata->connection);
        $this->assertFalse($metadata->traits->usesTimestamps);

        // Casts map preserves the original-case column key — regression guard against
        // anyone re-introducing strtolower() in the computeCasts path.
        $casts = $metadata->casts();
        $this->assertArrayHasKey('CreatedAt', $casts);
        $this->assertArrayNotHasKey('createdat', $casts);
    }

    #[Test]
    public function inbound_cast_resolves_to_column_base_type(): void
    {
        // Headline of the registry migration: computeCasts threads the column's base type as the
        // 4th `CastResolver::resolve` arg ($originalType). A write-only CastsInboundAttributes cast
        // reads back as that base type (string), NOT mixed. Drop the thread → silent mixed regression.
        $this->seedSchema('inbound_cast_models', [
            new SchemaColumn('code', SchemaColumn::TYPE_STRING),
        ]);

        $codebase = $this->makeCodebase();
        $this->registerStorage(InboundCastModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, InboundCastModel::class);

        $metadata = ModelMetadataRegistry::for(InboundCastModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        $this->assertArrayHasKey('code', $casts);
        $this->assertFalse(
            $casts['code']->psalmType->isMixed(),
            'Inbound (write-only) cast must read back as the column base type, not mixed',
        );
        $this->assertTrue($casts['code']->psalmType->hasString());
    }

    #[Test]
    public function cast_on_nullable_column_bakes_nullable_psalm_type(): void
    {
        // Column nullability flows into CastInfo::$psalmType at warm-up. CustomDeletedAtModel maps
        // its SoftDeletes column to `archived_at`; a nullable schema column must yield Carbon|null.
        $this->seedSchema('custom_deleted_at_models', [
            new SchemaColumn('archived_at', SchemaColumn::TYPE_STRING, nullable: true),
        ]);

        $codebase = $this->makeCodebase();
        $this->registerStorage(CustomDeletedAtModel::class, [SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, CustomDeletedAtModel::class);

        $metadata = ModelMetadataRegistry::for(CustomDeletedAtModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        $this->assertArrayHasKey('archived_at', $casts);
        $this->assertTrue(
            $casts['archived_at']->psalmType->isNullable(),
            'A cast on a nullable column must bake |null into the resolved type',
        );
    }

    #[Test]
    public function custom_builder_and_collection_classes_are_populated(): void
    {
        // WorkOrder declares #[UseEloquentBuilder(WorkOrderBuilder)] and #[CollectedBy(WorkOrderCollection)].
        // The registry records both via the pure resolvers shared with ModelRegistrationHandler.
        $codebase = $this->makeCodebase();
        $this->registerStorage(WorkOrder::class, [SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

        $metadata = ModelMetadataRegistry::for(WorkOrder::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertSame(WorkOrderBuilder::class, $metadata->customBuilder);
        $this->assertSame(WorkOrderCollection::class, $metadata->customCollection);
    }

    #[Test]
    public function phase_2_getters_throw_logic_exception(): void
    {
        $metadata = $this->makeStubMetadata(WorkOrder::class);

        $this->expectException(\LogicException::class);
        $metadata->accessors();
    }

    #[Test]
    public function phase_3_known_properties_throws_logic_exception(): void
    {
        $metadata = $this->makeStubMetadata(WorkOrder::class);

        $this->expectException(\LogicException::class);
        $metadata->knownProperties();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, new VoidProgress());

        return $codebase;
    }

    /**
     * @param class-string                $fqcn
     * @param list<class-string>          $traits
     */
    private function registerStorage(string $fqcn, array $traits = []): ClassLikeStorage
    {
        $storage = $this->classLikeStorageProvider->create($fqcn);

        foreach ($traits as $trait) {
            $storage->used_traits[\strtolower($trait)] = $trait;
        }

        return $storage;
    }

    /**
     * @param list<SchemaColumn> $columns
     */
    private function seedSchema(string $tableName, array $columns): void
    {
        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        foreach ($columns as $column) {
            $table->setColumn($column);
        }

        $schema->tables[$tableName] = $table;

        SchemaStateProvider::setSchema($schema);
    }

    /**
     * @param class-string<Model> $fqcn
     * @return ModelMetadata<Model>
     */
    private function makeStubMetadata(string $fqcn): ModelMetadata
    {
        return new ModelMetadata(
            fqcn: $fqcn,
            primaryKey: new PrimaryKeyInfo('id', PrimaryKeyType::Integer, incrementing: true, uuidColumns: []),
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
            connection: null,
            morphAlias: null,
            customBuilder: null,
            customCollection: null,
            schemaData: new TableSchema([]),
            castsData: [],
        );
    }
}
