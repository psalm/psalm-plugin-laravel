<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use App\Builders\WorkOrderBuilder;
use App\Collections\WorkOrderCollection;
use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomPkUuidModel;
use App\Models\SpecializationPivot;
use App\Models\UlidModel;
use App\Models\UnguardedModel;
use App\Models\UuidModel;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AttributeAccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AttributeMutatorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AttributeScopeInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastShape;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\LegacyAccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\LegacyMutatorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\LegacyScopeInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Providers\ModelMetadata\RelationInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TableSchema;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TraitFlags;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
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
    public function guarded_false_idiom_does_not_crash_warm_up(): void
    {
        // Laravel's `$guarded = false` ("guard nothing", used by laravel/passport's models) makes
        // getGuarded() return a bool, not an array. Before #591's filterStringList(mixed) fix this
        // TypeError-ed and warmUp()'s catch swallowed it, leaving NO registry entry — so the migrated
        // ModelPropertyHandler saw no schema and every column read became UndefinedMagicPropertyFetch.
        $codebase = $this->makeCodebase();
        $this->registerStorage(UnguardedModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, UnguardedModel::class);

        $metadata = ModelMetadataRegistry::for(UnguardedModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata, 'warm-up must not fail on $guarded = false');
        // `$guarded = false` means guard nothing → empty list (not the base default ['*']).
        $this->assertSame([], $metadata->guarded);
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
    // Accessors / mutators (storage-derived, full-callable)
    // ---------------------------------------------------------------------

    #[Test]
    public function legacy_accessor_populates_accessor_map(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod($storage, 'getFullNameAttribute', Type::getString());

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $metadata = $this->metadataFor(Customer::class);

        // Keyed by the separator-collapsed lowercase identity ('fullname', not 'full_name') — the
        // spelling-independent form Laravel resolves regardless of access spelling.
        $accessor = $metadata->accessors()['fullname'] ?? null;
        $this->assertInstanceOf(LegacyAccessorInfo::class, $accessor);
        $this->assertTrue($accessor->returnType->hasString());
        // A read-only legacy accessor produces no mutator entry.
        $this->assertArrayNotHasKey('fullname', $metadata->mutators());
    }

    #[Test]
    public function legacy_setter_is_a_write_only_mutator(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod($storage, 'setNicknameAttribute', Type::getVoid());

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $metadata = $this->metadataFor(Customer::class);

        // setXxxAttribute with no matching getXxxAttribute: a write-only mutator, no accessor.
        $this->assertInstanceOf(LegacyMutatorInfo::class, $metadata->mutators()['nickname'] ?? null);
        $this->assertArrayNotHasKey('nickname', $metadata->accessors());
    }

    #[Test]
    public function attribute_accessor_with_setter_flags_mutator(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        // Attribute<string, string>: a get+set attribute → accessor with hasMutator + paired mutator.
        $this->defineAppearingMethod($storage, 'firstName', $this->attributeReturn(Type::getString(), Type::getString()));

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $metadata = $this->metadataFor(Customer::class);

        $accessor = $metadata->accessors()['firstname'] ?? null;
        $this->assertInstanceOf(AttributeAccessorInfo::class, $accessor);
        $this->assertTrue($accessor->hasMutator);
        $this->assertTrue($accessor->returnType->hasString(), 'returnType is TGet');

        $mutator = $metadata->mutators()['firstname'] ?? null;
        $this->assertInstanceOf(AttributeMutatorInfo::class, $mutator);
        $this->assertSame('firstname', $mutator->accessorPropertyName);
        // setType is the Attribute<TGet, TSet> setter type — the value the write-path bakes into
        // pseudo_property_set_types (here TSet = string).
        $this->assertTrue($mutator->setType->hasString());
    }

    #[Test]
    public function read_only_attribute_accessor_has_no_mutator(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        // Attribute<string, never>: TSet=never means read-only — no mutator entry, hasMutator=false.
        $this->defineAppearingMethod($storage, 'displayName', $this->attributeReturn(Type::getString(), Type::getNever()));

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $metadata = $this->metadataFor(Customer::class);

        $accessor = $metadata->accessors()['displayname'] ?? null;
        $this->assertInstanceOf(AttributeAccessorInfo::class, $accessor);
        $this->assertFalse($accessor->hasMutator);
        $this->assertArrayNotHasKey('displayname', $metadata->mutators());
    }

    #[Test]
    public function acronym_accessor_collapses_to_laravel_resolution_key(): void
    {
        // getApiURLAttribute() must key as 'apiurl' (separators stripped, lowercased) so it resolves via
        // $model->api_url / $model->apiUrl — Laravel's Str::studly equivalence. A snake-INSERTING
        // normalizer would key it 'api_u_r_l' and miss every real access spelling.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod($storage, 'getApiURLAttribute', Type::getString());

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertArrayHasKey('apiurl', $this->metadataFor(Customer::class)->accessors());
    }

    #[Test]
    public function inherited_accessor_is_reached_through_the_ancestor_walk(): void
    {
        // The accessor is declared on the PARENT's storage, not the child's, so a naive
        // $storage->methods walk on the child would miss it. computeAccessorsAndMutators replays
        // appearingMethods() over parent_classes, mirroring methodExists()'s inheritance resolution.
        $codebase = $this->makeCodebase();
        $child = $this->registerStorage(Contract::class);
        // parent_classes: lowercase key → ORIGINAL-CASE value, exactly as Psalm's Populator stores it
        // (a lowercase value would let the builder's framework-skip misfire and hide regressions).
        $child->parent_classes[\strtolower(AbstractDocument::class)] = AbstractDocument::class;
        $parent = $this->registerStorage(AbstractDocument::class);
        $this->defineAppearingMethod($parent, 'getReferenceCodeAttribute', Type::getString());

        ModelMetadataRegistryBuilder::warmUp($codebase, Contract::class);

        $this->assertInstanceOf(
            LegacyAccessorInfo::class,
            $this->metadataFor(Contract::class)->accessors()['referencecode'] ?? null,
        );
    }

    #[Test]
    public function abstract_base_populates_accessors_without_instantiation(): void
    {
        // computeForAbstract never instantiates, yet the storage-derived accessor map still populates.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(AbstractDocument::class);
        $this->defineAppearingMethod($storage, 'getReferenceCodeAttribute', Type::getString());

        ModelMetadataRegistryBuilder::warmUp($codebase, AbstractDocument::class);

        $this->assertArrayHasKey('referencecode', $this->metadataFor(AbstractDocument::class)->accessors());
    }

    #[Test]
    public function attribute_accessor_wins_over_legacy_for_same_property(): void
    {
        // A property declared BOTH as legacy getFooAttribute and attribute-style foo(): Attribute —
        // the read handler resolved new-style first, so the map must keep the AttributeAccessorInfo.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod($storage, 'getFooAttribute', Type::getString());
        $this->defineAppearingMethod($storage, 'foo', $this->attributeReturn(Type::getInt(), Type::getNever()));

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertInstanceOf(
            AttributeAccessorInfo::class,
            $this->metadataFor(Customer::class)->accessors()['foo'] ?? null,
        );
    }

    #[Test]
    public function most_derived_accessor_wins_over_inherited(): void
    {
        // Child and parent both declare getFooAttribute; the child's (most-derived) declaration wins,
        // because callableMethodStorages walks self before ancestors and insertAccessor keeps the first.
        $codebase = $this->makeCodebase();
        $child = $this->registerStorage(Customer::class);
        $child->parent_classes[\strtolower(AbstractDocument::class)] = AbstractDocument::class;
        $parent = $this->registerStorage(AbstractDocument::class);
        $this->defineAppearingMethod($child, 'getFooAttribute', Type::getString());
        $this->defineAppearingMethod($parent, 'getFooAttribute', Type::getInt());

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $accessor = $this->metadataFor(Customer::class)->accessors()['foo'] ?? null;
        $this->assertInstanceOf(LegacyAccessorInfo::class, $accessor);
        $this->assertTrue($accessor->returnType->hasString(), 'child (most-derived) returnType wins');
        $this->assertFalse($accessor->returnType->hasInt());
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
    public function concrete_model_without_guards_inherits_framework_defaults(): void
    {
        // Concrete path (computeForInstance -> getFillable()/getGuarded()). A model that declares
        // neither $fillable nor $guarded inherits Eloquent's defaults: guarded ['*'], fillable [].
        // The abstract path's ['*'] is pinned separately (computeForAbstract reads
        // getDefaultProperties()); the two derive the default through different code, so each path
        // needs its own guard against regression.
        $codebase = $this->makeCodebase();
        $this->registerStorage(UuidModel::class, [HasUuids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, UuidModel::class);

        $metadata = ModelMetadataRegistry::for(UuidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata::class, $metadata);
        $this->assertSame(['*'], $metadata->guarded);
        $this->assertSame([], $metadata->fillable);
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

    // ---------------------------------------------------------------------
    // Scopes (storage-derived, full-callable; identity only)
    // ---------------------------------------------------------------------

    #[Test]
    public function legacy_scope_keys_by_stripped_name_and_drops_query_param(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        // scopePublished(Builder $query) → key 'published', params [] (the $query is sliced off).
        $this->defineAppearingMethod(
            $storage,
            'scopePublished',
            new Union([new TNamedObject(Builder::class)]),
            params: [$this->queryParam()],
        );

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $scope = $this->metadataFor(Customer::class)->scopes()['published'] ?? null;
        $this->assertInstanceOf(LegacyScopeInfo::class, $scope);
        $this->assertSame([], $scope->parameters, 'the leading Builder $query is excluded');
    }

    #[Test]
    public function attribute_scope_keys_by_bare_name_and_keeps_extra_params(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        // #[Scope] published(Builder $query, int $minViews) → key 'published', params [int $minViews].
        $this->defineAppearingMethod(
            $storage,
            'published',
            new Union([new TNamedObject(Builder::class)]),
            params: [$this->queryParam(), new FunctionLikeParameter('minViews', false, Type::getInt())],
            scopeAttribute: true,
        );

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $scope = $this->metadataFor(Customer::class)->scopes()['published'] ?? null;
        $this->assertInstanceOf(AttributeScopeInfo::class, $scope);
        $this->assertCount(1, $scope->parameters);
        $this->assertSame('minViews', $scope->parameters[0]->name);
    }

    #[Test]
    public function attribute_scope_beats_legacy_twin_of_the_same_name(): void
    {
        // Laravel's Model::callNamedScope checks #[Scope] methods before legacy scopeXxx, so an
        // attribute scope must win when both spell the same key. Mirrors insertAccessor precedence.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod($storage, 'scopeActive', new Union([new TNamedObject(Builder::class)]), params: [$this->queryParam()]);
        $this->defineAppearingMethod($storage, 'active', new Union([new TNamedObject(Builder::class)]), params: [$this->queryParam()], scopeAttribute: true);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertInstanceOf(AttributeScopeInfo::class, $this->metadataFor(Customer::class)->scopes()['active'] ?? null);
    }

    #[Test]
    public function scope_attribute_on_a_legacy_named_method_produces_both_entries(): void
    {
        // `#[Scope] scopePublished()` is dispatchable both as ->scopePublished() (the attribute,
        // keyed by the bare name) and ->published() (the legacy strip) — Laravel's callNamedScope
        // resolves each form independently, so the registry keeps both entries.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod(
            $storage,
            'scopePublished',
            new Union([new TNamedObject(Builder::class)]),
            params: [$this->queryParam()],
            scopeAttribute: true,
        );

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $scopes = $this->metadataFor(Customer::class)->scopes();

        $this->assertInstanceOf(AttributeScopeInfo::class, $scopes['scopepublished'] ?? null);
        $this->assertInstanceOf(LegacyScopeInfo::class, $scopes['published'] ?? null);
    }

    #[Test]
    public function private_attribute_scope_is_not_registered(): void
    {
        // A private #[Scope] cannot dispatch on any supported Laravel (EloquentModelMethods::hasScopeAttribute
        // rejects it), so it must not appear in the scope map.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod(
            $storage,
            'secret',
            new Union([new TNamedObject(Builder::class)]),
            visibility: ClassLikeAnalyzer::VISIBILITY_PRIVATE,
            params: [$this->queryParam()],
            scopeAttribute: true,
        );

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertArrayNotHasKey('secret', $this->metadataFor(Customer::class)->scopes());
    }

    #[Test]
    public function trait_hosted_scope_is_reached(): void
    {
        // A scope whose declaring_fqcln is a trait (not the model body) must still classify — the
        // full-callable walk yields it from the carrier's appearing_method_ids.
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        $this->defineAppearingMethod(
            $storage,
            'scopeArchived',
            new Union([new TNamedObject(Builder::class)]),
            declaringFqcn: 'App\\Concerns\\Archivable',
            params: [$this->queryParam()],
        );

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertInstanceOf(LegacyScopeInfo::class, $this->metadataFor(Customer::class)->scopes()['archived'] ?? null);
    }

    #[Test]
    public function inherited_scope_from_abstract_base_resolves_on_child(): void
    {
        // The scope is declared on the abstract parent; the full-callable walk over parent_classes
        // surfaces it on the concrete child (mirrors BuilderScopeHandler's methodExists resolution).
        $codebase = $this->makeCodebase();
        $child = $this->registerStorage(Contract::class);
        $child->parent_classes[\strtolower(AbstractDocument::class)] = AbstractDocument::class;
        $parent = $this->registerStorage(AbstractDocument::class);
        $this->defineAppearingMethod($parent, 'scopeDraft', new Union([new TNamedObject(Builder::class)]), params: [$this->queryParam()]);

        ModelMetadataRegistryBuilder::warmUp($codebase, Contract::class);

        $this->assertInstanceOf(LegacyScopeInfo::class, $this->metadataFor(Contract::class)->scopes()['draft'] ?? null);
    }

    #[Test]
    public function abstract_base_populates_scopes_without_instantiation(): void
    {
        // computeForAbstract never instantiates, yet the storage-derived scope map still populates
        // (so a scope resolves on a Builder<AbstractBase> receiver — #901).
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(AbstractDocument::class);
        $this->defineAppearingMethod($storage, 'scopeDraft', new Union([new TNamedObject(Builder::class)]), params: [$this->queryParam()]);

        ModelMetadataRegistryBuilder::warmUp($codebase, AbstractDocument::class);

        $this->assertInstanceOf(LegacyScopeInfo::class, $this->metadataFor(AbstractDocument::class)->scopes()['draft'] ?? null);
    }

    #[Test]
    public function model_without_scopes_has_empty_scope_map(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(Customer::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertSame([], $this->metadataFor(Customer::class)->scopes());
    }

    // ---------------------------------------------------------------------
    // Relations (AST-parsed, OWN-CLASS). The end-to-end parse + handler path needs a real Codebase,
    // so it is gated by the tests/Type/ relation corpus; here we pin the getter, the value object,
    // and the compute guard.
    // ---------------------------------------------------------------------

    #[Test]
    public function relations_getter_returns_the_stored_map(): void
    {
        $relation = new RelationInfo(
            name: 'posts',
            relationClass: HasMany::class,
            relatedModel: 'App\\Models\\Post',
            generics: [],
        );

        ModelMetadataRegistryBuilder::overrideForTesting(
            Customer::class,
            $this->makeStubMetadata(Customer::class, ['posts' => $relation]),
        );

        $relations = $this->metadataFor(Customer::class)->relations();
        $this->assertSame($relation, $relations['posts'] ?? null);
        $this->assertSame('App\\Models\\Post', $relations['posts']->relatedModel);
    }

    #[Test]
    public function relations_are_empty_without_a_populated_codebase(): void
    {
        // computeRelations short-circuits when Codebase::$methods is uninitialized (a unit-test
        // Codebase built via newInstanceWithoutConstructor), because RelationMethodParser reads
        // parsed file statements. So warmUp() yields an empty relation map here — the real parse is
        // exercised by the tests/Type/ corpus. Pin the guard so dropping it surfaces loudly.
        $codebase = $this->makeCodebase();
        $this->registerStorage(Customer::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $this->assertSame([], $this->metadataFor(Customer::class)->relations());
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
    private function metadataFor(string $fqcn): ModelMetadata
    {
        $metadata = ModelMetadataRegistry::for($fqcn);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);

        return $metadata;
    }

    /** Build an `Attribute<TGet, TSet>` return type for an attribute-style accessor fixture. */
    private function attributeReturn(Union $get, Union $set): Union
    {
        return new Union([new TGenericObject(Attribute::class, [$get, $set])]);
    }

    /**
     * Wire a method into the storage graph so {@see EloquentModelMethods::appearingMethods()} yields
     * it for $carrier: the method APPEARS on $carrier but is DECLARED on $declaringFqcn (pass a trait
     * FQCN for a trait-hosted method; defaults to the carrier itself). The MethodStorage lives on the
     * declaring class's storage — mirroring how Psalm stores trait/inherited methods — because
     * registerStorage() alone produces an empty method graph.
     *
     * Pass $params (e.g. a leading `Builder $query` plus extra args) to exercise scope param
     * slicing, and $scopeAttribute to attach a `#[Scope]` so the method classifies as an
     * attribute-style scope.
     *
     * @param class-string|null            $declaringFqcn
     * @param list<FunctionLikeParameter>  $params
     */
    private function defineAppearingMethod(
        ClassLikeStorage $carrier,
        string $casedName,
        Union $returnType,
        ?string $declaringFqcn = null,
        int $visibility = ClassLikeAnalyzer::VISIBILITY_PUBLIC,
        array $params = [],
        bool $scopeAttribute = false,
    ): void {
        $declaringFqcn ??= $carrier->name;
        $lower = \strtolower($casedName);

        $methodStorage = new MethodStorage();
        $methodStorage->cased_name = $casedName;
        $methodStorage->defining_fqcln = $declaringFqcn;
        $methodStorage->visibility = $visibility;
        $methodStorage->return_type = $returnType;
        $methodStorage->params = $params;
        if ($scopeAttribute) {
            $methodStorage->attributes = [$this->scopeAttributeStorage()];
        }

        $declaringStorage = $this->classLikeStorageProvider->has($declaringFqcn)
            ? $this->classLikeStorageProvider->get($declaringFqcn)
            : $this->classLikeStorageProvider->create($declaringFqcn);
        $declaringStorage->methods[$lower] = $methodStorage;

        $carrier->appearing_method_ids[$lower] = new MethodIdentifier($carrier->name, $lower);
        $carrier->declaring_method_ids[$lower] = new MethodIdentifier($declaringFqcn, $lower);
    }

    /**
     * Build a scope query parameter (`Builder $query`) — the leading param Laravel injects and the
     * registry slices off. {@see scopeAttributeStorage} for the `#[Scope]` marker.
     */
    private function queryParam(): FunctionLikeParameter
    {
        return new FunctionLikeParameter('query', false, new Union([new TNamedObject(Builder::class)]));
    }

    /**
     * An {@see AttributeStorage} carrying only the `#[Scope]` FQCN — enough for
     * {@see \Psalm\LaravelPlugin\Util\EloquentModelMethods::hasScopeAttribute}, which reads only
     * `fq_class_name`. Built via reflection because AttributeStorage's constructor demands a
     * CodeLocation that a storage-only unit fixture has no source node to produce.
     */
    private function scopeAttributeStorage(): AttributeStorage
    {
        $attribute = (new \ReflectionClass(AttributeStorage::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AttributeStorage::class, 'fq_class_name'))->setValue($attribute, Scope::class);

        return $attribute;
    }

    /**
     * @param class-string<Model> $fqcn
     * @param array<non-empty-lowercase-string, RelationInfo> $relations
     * @return ModelMetadata<Model>
     */
    private function makeStubMetadata(string $fqcn, array $relations = []): ModelMetadata
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
            accessorsData: [],
            mutatorsData: [],
            scopesData: [],
            relationsData: $relations,
        );
    }
}
