<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use App\Models\AbstractUuidModel;
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
        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, ModelMetadataRegistry::for(WorkOrder::class));
    }

    #[Test]
    public function for_returns_null_for_non_model_class(): void
    {
        $codebase = $this->makeCodebase();
        ModelMetadataRegistryBuilder::warmUp($codebase, \stdClass::class);

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, ModelMetadataRegistry::for(\stdClass::class));
    }

    #[Test]
    public function for_returns_null_for_unloadable_class(): void
    {
        $codebase = $this->makeCodebase();
        ModelMetadataRegistryBuilder::warmUp($codebase, 'NonExistent\\Model');

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, ModelMetadataRegistry::for('NonExistent\\Model'));
    }

    #[Test]
    public function for_returns_null_for_abstract_model(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(AbstractUuidModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AbstractUuidModel::class);

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, ModelMetadataRegistry::for(AbstractUuidModel::class));
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

        $this->assertNotInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, ModelMetadataRegistry::for(WorkOrder::class));
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

        // Values arrive lowercased per the §5.5 naming convention.
        $this->assertSame(['name', 'email'], $metadata->fillable);
        $this->assertSame(['id'], $metadata->guarded);
        $this->assertSame(['password'], $metadata->hidden);
        $this->assertSame(['fullname'], $metadata->appends);
        $this->assertSame(['author'], $metadata->with);
        $this->assertSame(['comments'], $metadata->withCount);
        $this->assertSame('reporting', $metadata->connection);
        $this->assertFalse($metadata->traits->usesTimestamps);
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
