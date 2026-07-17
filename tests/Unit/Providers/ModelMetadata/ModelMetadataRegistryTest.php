<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers\ModelMetadata;

use App\Builders\WorkOrderBuilder;
use App\Collections\WorkOrderCollection;
use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomPkUuidModel;
use App\Models\KeylessPermission;
use App\Models\SpecializationPivot;
use App\Models\UlidModel;
use App\Models\UnguardedModel;
use App\Models\UuidModel;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AttributeAccessorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AttributeMutatorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AttributeScopeInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastShape;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\LegacyAccessorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\LegacyMutatorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\LegacyScopeInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PropertyOrigin;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\RelationInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TableSchema;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TraitFlags;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\AttributeStorage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AbstractKeylessModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AppendsOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ArrayFormCastsModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AttributeConfiguredChild;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AttributeConfiguredModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AttributeOverriddenByPropertyModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CollectionCastVariantsModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\CustomDeletedAtModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\EnumConnectionModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\InboundCastModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\NonStringDeclaredCastsModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\PlainEnumConnectionModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\RawCastInitializerModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\SectionFailureModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TableKeyAttributeModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TableTimestampsAttributeModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TableTimestampsEnabledModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TimestampsAttributeInheritedModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TimestampsAttributePrecedenceModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TimestampsInitializerOrderModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\TraitInitializedConfigModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\UnguardedAttributeModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\WithoutIncrementingAttributeModel;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\WithoutTimestampsAttributeModel;

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
        SectionFailureModel::$failures = [];
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

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
    public function abstract_model_preserves_explicitly_null_primary_key_defaults(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(AbstractKeylessModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AbstractKeylessModel::class);

        $metadata = $this->metadataFor(AbstractKeylessModel::class);
        $this->assertNull($metadata->primaryKey->name);
        $this->assertFalse($metadata->primaryKey->incrementing);
    }

    #[Test]
    public function keyless_model_preserves_metadata_and_explicit_casts(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(KeylessPermission::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, KeylessPermission::class);

        $metadata = $this->metadataFor(KeylessPermission::class);
        $this->assertNull($metadata->primaryKey->name);
        $this->assertSame(PrimaryKeyType::Integer, $metadata->primaryKey->type);
        $this->assertTrue($metadata->primaryKey->incrementing);
        $this->assertSame([], $metadata->primaryKey->uuidColumns);
        $casts = $metadata->casts();
        $this->assertArrayHasKey('allowed', $casts);
        $this->assertSame('bool|null', $casts['allowed']->psalmType->getId());
        $this->assertArrayNotHasKey('', $casts);
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
    public function model_without_class_attributes_survives_warm_up_on_laravel_12(): void
    {
        // Regression for #1254: Laravel 12.14 has none of the four configuration attributes, while
        // mergeHidden()/mergeVisible()/mergeAppends() are also unavailable. Attribute replay must be
        // a no-op instead of calling one of those missing helpers with an empty list.
        $codebase = $this->makeCodebase();
        $this->registerStorage(ScalarFieldsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, ScalarFieldsModel::class);

        $metadata = ModelMetadataRegistry::for(ScalarFieldsModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata, 'Laravel 12 model warm-up must succeed');
        $this->assertSame(['Password'], $metadata->hidden);
        $this->assertSame(['Name', 'EMAIL'], $metadata->visible);
        $this->assertSame(['FullName'], $metadata->appends);
        $this->assertSame(['Name', 'EMAIL'], $metadata->fillable);
    }

    #[Test]
    public function class_attributes_are_merged_at_warm_up(): void
    {
        // newInstanceWithoutConstructor() skips initializeTraits()/initializeModelAttributes(), so the
        // PHP-attribute config is missed unless ModelInstancePreparer::prepare() replays them. The #[*]
        // classes only exist from Laravel 13.0, below which this fixture is never loaded.
        if (!class_exists(Hidden::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage(AttributeConfiguredModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AttributeConfiguredModel::class);

        $metadata = ModelMetadataRegistry::for(AttributeConfiguredModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);

        // #[Hidden]/#[Visible]/#[Appends]/#[Fillable] union-merge into the $property baseline.
        $this->assertSame(['prop_hidden', 'attr_hidden'], $metadata->hidden);
        $this->assertSame(['prop_visible', 'attr_visible'], $metadata->visible);
        $this->assertSame(['prop_append', 'attr_append'], $metadata->appends);
        $this->assertSame(['prop_fillable', 'attr_fillable'], $metadata->fillable);

        // #[Guarded] replaces the default ['*'] denylist; #[Connection] fills the null connection.
        $this->assertSame(['attr_guarded'], $metadata->guarded);
        $this->assertSame('attr_connection', $metadata->connection);
    }

    #[Test]
    public function table_attribute_redirects_the_schema_lookup(): void
    {
        // #[Table('attr_table')] must drive computeSchema()'s getTable() lookup: the seeded column only
        // surfaces if the attribute table name reached the schema aggregator.
        if (!class_exists(Table::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        $schema = SchemaStateProvider::getSchema();
        $this->assertInstanceOf(SchemaAggregator::class, $schema);
        $schemaTable = new SchemaTable();
        $schemaTable->setColumn(new SchemaColumn('flagged', SchemaColumn::TYPE_BOOL));

        $schema->setTable('attr_table', $schemaTable);

        $codebase = $this->makeCodebase();
        $this->registerStorage(AttributeConfiguredModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AttributeConfiguredModel::class);

        $metadata = ModelMetadataRegistry::for(AttributeConfiguredModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->schema()->has('flagged'), '#[Table] name must reach computeSchema()');
    }

    #[Test]
    public function unguarded_attribute_empties_the_guard_list(): void
    {
        if (!class_exists(Hidden::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage(UnguardedAttributeModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, UnguardedAttributeModel::class);

        $metadata = ModelMetadataRegistry::for(UnguardedAttributeModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);
        // #[Unguarded] replaces the default ['*'] denylist with [] (guard nothing) — distinct from the
        // $guarded = false idiom that guarded_false_idiom_does_not_crash_warm_up covers.
        $this->assertSame([], $metadata->guarded);
    }

    #[Test]
    public function an_explicit_property_wins_over_a_matching_attribute(): void
    {
        if (!class_exists(Hidden::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        // Seed the table under the model's OWN $table name; if #[Table] wrongly overrode it, getTable()
        // would look the column up under 'attr_table' and miss it.
        $schema = SchemaStateProvider::getSchema();
        $this->assertInstanceOf(SchemaAggregator::class, $schema);
        $schemaTable = new SchemaTable();
        $schemaTable->setColumn(new SchemaColumn('own_col', SchemaColumn::TYPE_STRING));

        $schema->setTable('own_table', $schemaTable);

        $codebase = $this->makeCodebase();
        $this->registerStorage(AttributeOverriddenByPropertyModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AttributeOverriddenByPropertyModel::class);

        $metadata = ModelMetadataRegistry::for(AttributeOverriddenByPropertyModel::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);
        // Explicit $guarded is no longer ['*'], so #[Guarded] does not replace it.
        $this->assertSame(['own_guard'], $metadata->guarded);
        // Own $table wins over #[Table], so the schema resolves under 'own_table'.
        $this->assertTrue($metadata->schema()->has('own_col'), 'own $table must win over #[Table]');
    }

    /**
     * Each case pairs a fixture with the `usesTimestamps()` its attributes must produce. #1276.
     *
     * @return iterable<string, array{class-string<Model>, bool}>
     */
    public static function timestampsAttributeCases(): iterable
    {
        yield '#[WithoutTimestamps]' => [WithoutTimestampsAttributeModel::class, false];
        yield '#[Table(timestamps: false)]' => [TableTimestampsAttributeModel::class, false];
        yield '#[WithoutTimestamps] outranks #[Table(timestamps: true)]' => [TimestampsAttributePrecedenceModel::class, false];
        // Inherited #[WithoutTimestamps] outranks the CHILD's own #[Table] — precedence is decided across
        // the hierarchy, and only the ancestor walk finds the parent's attribute.
        yield 'inherited #[WithoutTimestamps] outranks the child #[Table]' => [TimestampsAttributeInheritedModel::class, false];
        yield 'declared $timestamps = false closes the guard' => [AttributeOverriddenByPropertyModel::class, false];
        // The only case that assigns `true` FROM the attribute rather than leaving the default.
        yield '#[Table(timestamps: true)]' => [TableTimestampsEnabledModel::class, true];
        // #[Table] carrying no timestamps: argument must leave them alone — pins that the mirror reads
        // the argument, not the attribute's presence.
        yield '#[Table] without a timestamps: argument' => [AttributeConfiguredModel::class, true];
    }

    /**
     * @param class-string<Model> $modelFqcn
     */
    #[Test]
    #[DataProvider('timestampsAttributeCases')]
    public function timestamps_attributes_reach_the_registry(string $modelFqcn, bool $expected): void
    {
        // #[WithoutTimestamps] is Laravel 13.2 (#[Table] 13.0). Below that these fixtures' attributes are
        // inert: 12.x has neither an initializeHasTimestamps() for the walk to invoke nor an
        // initializeModelAttributes() phase, so the premise assertion fails.
        if (!class_exists(WithoutTimestamps::class)) {
            self::markTestSkipped('#[WithoutTimestamps] requires Laravel >= 13.2.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage($modelFqcn);

        ModelMetadataRegistryBuilder::warmUp($codebase, $modelFqcn);

        $metadata = ModelMetadataRegistry::for($modelFqcn);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);

        // Warm-up must have COMPLETED, not degraded: every failure path falls back to usesTimestamps=true,
        // which the true-expecting cases below cannot tell from a correct read. Only this section — the
        // fixtures seed no migrations, so SECTION_SCHEMA is legitimately incomplete.
        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_RUNTIME_CONFIGURATION));

        // A constructed model is the oracle: it runs the real initializeHasTimestamps() that
        // newInstanceWithoutConstructor() skips, so this stays honest if Laravel changes the rules.
        $runtime = (new $modelFqcn())->usesTimestamps();

        // Pin the premise first. Without it, a Laravel that stopped applying these attributes would make
        // both sides agree on true and the oracle below would pass while testing nothing.
        $this->assertSame($expected, $runtime, 'fixture premise drifted from Laravel behaviour');
        $this->assertSame($runtime, $metadata->traits->usesTimestamps);
    }

    /**
     * @return iterable<string, array{class-string<Model>, string, string, bool}>
     */
    public static function primaryKeyAttributeCases(): iterable
    {
        yield '#[Table] key/keyType/incrementing sub-overrides' => [TableKeyAttributeModel::class, 'uuid', 'string', false];
        yield '#[WithoutIncrementing]' => [WithoutIncrementingAttributeModel::class, 'id', 'int', false];
    }

    /**
     * @param class-string<Model> $modelFqcn
     */
    #[Test]
    #[DataProvider('primaryKeyAttributeCases')]
    public function primary_key_attributes_reach_the_registry(
        string $modelFqcn,
        string $expectedKeyName,
        string $expectedKeyType,
        bool $expectedIncrementing,
    ): void {
        // Gated on the LATER of the two attributes: `#[Table]` is 13.0 but `#[WithoutIncrementing]` is 13.2,
        // and 12.x has no initializeModelAttributes() phase to apply either — the premise assertions would
        // fail there. Below 13.2 the `#[WithoutIncrementing]` row would also name-match a class that is not
        // installed, which resolveClassAttribute()'s newInstance() throws on.
        if (!class_exists(WithoutIncrementing::class)) {
            self::markTestSkipped('#[WithoutIncrementing] requires Laravel >= 13.2.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage($modelFqcn);

        ModelMetadataRegistryBuilder::warmUp($codebase, $modelFqcn);

        $metadata = ModelMetadataRegistry::for($modelFqcn);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);

        // Warm-up must have COMPLETED, not degraded: the fallback key is ('id', Integer, incrementing: true),
        // which the expectations below could not tell from a correct read of a default-keyed model.
        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_PRIMARY_KEY));

        // A constructed model is the oracle: it runs the real initializeModelAttributes() that
        // newInstanceWithoutConstructor() skips, so this stays honest if Laravel changes the rules.
        $runtime = new $modelFqcn();

        // Pin the premise first. Without it, a Laravel that stopped applying these attributes would leave the
        // oracle and the registry agreeing on the defaults and the comparison below would test nothing.
        $this->assertSame($expectedKeyName, $runtime->getKeyName(), 'fixture premise drifted from Laravel behaviour');
        $this->assertSame($expectedKeyType, $runtime->getKeyType(), 'fixture premise drifted from Laravel behaviour');
        $this->assertSame($expectedIncrementing, $runtime->getIncrementing(), 'fixture premise drifted from Laravel behaviour');

        $this->assertSame($runtime->getKeyName(), $metadata->primaryKey->name);
        $this->assertSame(
            $expectedKeyType === 'string' ? PrimaryKeyType::String : PrimaryKeyType::Integer,
            $metadata->primaryKey->type,
        );
        $this->assertSame($runtime->getIncrementing(), $metadata->primaryKey->incrementing);
    }

    #[Test]
    public function trait_initializer_ordering_decides_the_timestamps_outcome(): void
    {
        if (!class_exists(WithoutTimestamps::class)) {
            self::markTestSkipped('#[WithoutTimestamps] requires Laravel >= 13.2.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage(TimestampsInitializerOrderModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, TimestampsInitializerOrderModel::class);

        $metadata = $this->metadataFor(TimestampsInitializerOrderModel::class);
        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_RUNTIME_CONFIGURATION));

        // Runtime construction is the ONLY oracle — never a literal: getMethods() ranks the user
        // initializer against Model's inherited initializeHasTimestamps() differently per PHP version, so
        // the correct answer is true on 8.4 and false on 8.5. The replay is right only because it invokes
        // each initializer at its own rank; sorting the walk, or splitting it user-first/framework-last,
        // diverges under 8.4.
        $this->assertSame(
            (new TimestampsInitializerOrderModel())->usesTimestamps(),
            $metadata->traits->usesTimestamps,
            'initializeHasTimestamps() must be invoked at its own position in the getMethods() walk',
        );
    }

    #[Test]
    public function inherited_attributes_resolve_through_the_ancestor_walk(): void
    {
        if (!class_exists(Hidden::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage(AttributeConfiguredChild::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AttributeConfiguredChild::class);

        $metadata = ModelMetadataRegistry::for(AttributeConfiguredChild::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);
        // #[Hidden]/#[Appends] declared on the abstract base resolve on the concrete child. Two different
        // ancestor walks now: Laravel's own resolveClassAttribute() inside the invoked
        // initializeHidesAttributes() for #[Hidden], the plugin's classAttribute() for #[Appends].
        $this->assertSame(['base_hidden'], $metadata->hidden);
        $this->assertSame(['base_append'], $metadata->appends);
    }

    #[Test]
    public function an_int_backed_enum_connection_attribute_is_normalized_to_a_string(): void
    {
        // `#[Connection]` is 13.0 — gated on the attribute this row actually carries, not on the enum
        // support it relies on, which is older (see the property-default twin below).
        if (!class_exists(Connection::class)) {
            self::markTestSkipped('#[Connection] requires Laravel >= 13.0.');
        }

        $this->assertNormalizedEnumConnection(EnumConnectionModel::class);
    }

    #[Test]
    public function an_int_backed_enum_connection_property_is_normalized_to_a_string(): void
    {
        // No attribute here, so no attribute to gate on: the real boundary is Laravel 12.28, where
        // `$connection` widened to `\UnitEnum|string|null` and getConnectionName() started applying
        // enum_value(). Below it the getter hands back the enum object and null is the honest answer, so the
        // assertion would rightly fail. Gate on the CAPABILITY rather than re-encode that version number —
        // an int here means enum_value() ran.
        if (!\is_int((new PlainEnumConnectionModel())->getConnectionName())) {
            self::markTestSkipped('enum_value() on $connection requires Laravel >= 12.28.');
        }

        $this->assertNormalizedEnumConnection(PlainEnumConnectionModel::class);
    }

    /**
     * @param class-string<Model> $modelFqcn
     */
    private function assertNormalizedEnumConnection(string $modelFqcn): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage($modelFqcn);

        ModelMetadataRegistryBuilder::warmUp($codebase, $modelFqcn);

        $metadata = ModelMetadataRegistry::for($modelFqcn);
        // The model must survive warm-up: getConnectionName()'s enum_value() yields the enum's raw int
        // backing value, and that TypeErrors the ?string field OUTSIDE every section guard — so a regression
        // drops the whole entry rather than degrading one section, and this assertInstanceOf goes red.
        $this->assertInstanceOf(ModelMetadata::class, $metadata);
        $this->assertSame('1', $metadata->connection);
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
        $progress = new VoidProgress();
        ModelMetadataRegistry::init($progress);
        ModelMetadataRegistryBuilder::overrideForTesting(
            WorkOrder::class,
            $this->makeStubMetadata(WorkOrder::class),
        );

        ModelMetadataRegistryBuilder::reset();

        $this->assertNull(ModelMetadataRegistry::for(WorkOrder::class));
        $this->assertNull(ModelMetadataRegistry::getProgress());
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

        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function complete_empty_schema_is_distinct_from_unavailable_schema(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(SectionFailureModel::class, [HasUuids::class]);
        $this->seedSchema('section_failure_models', []);

        ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);
        $completeEmpty = $this->metadataFor(SectionFailureModel::class);

        $this->assertSame([], $completeEmpty->schema()->all());
        $this->assertTrue($completeEmpty->isComplete(ModelMetadata::SECTION_SCHEMA));
        $this->assertFalse($completeEmpty->casts()['flag']->psalmType->isNullable());

        ModelMetadataRegistryBuilder::reset();
        SchemaStateProvider::setSchema(new SchemaAggregator());
        ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);
        $unavailable = $this->metadataFor(SectionFailureModel::class);

        $this->assertSame([], $unavailable->schema()->all());
        $this->assertFalse($unavailable->isComplete(ModelMetadata::SECTION_SCHEMA));
        $this->assertTrue($unavailable->isComplete(ModelMetadata::SECTION_CASTS));
        $this->assertTrue($unavailable->casts()['flag']->psalmType->isNullable());
        $this->assertTrue($unavailable->casts()['code']->psalmType->hasMixed());
    }

    #[Test]
    public function a_failed_section_preserves_independent_metadata(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(SectionFailureModel::class, [HasUuids::class]);
        $this->defineAppearingMethod($storage, 'getLabelAttribute', Type::getString());

        $expectations = [
            'runtime configuration' => [ModelMetadata::SECTION_RUNTIME_CONFIGURATION],
            'schema' => [ModelMetadata::SECTION_SCHEMA],
            'casts' => [ModelMetadata::SECTION_CASTS],
            'primary key' => [ModelMetadata::SECTION_PRIMARY_KEY],
            // A trait-initializer replay failure withholds every instance-derived section (a half-run
            // initializer may have mutated any of $table / $connection / casts / $appends / the key), so
            // all four gate on it — while the static method metadata (SECTION_METHODS) survives.
            'trait initializers' => [
                ModelMetadata::SECTION_RUNTIME_CONFIGURATION,
                ModelMetadata::SECTION_SCHEMA,
                ModelMetadata::SECTION_CASTS,
                ModelMetadata::SECTION_PRIMARY_KEY,
            ],
        ];

        foreach ($expectations as $failure => $incompleteSections) {
            ModelMetadataRegistryBuilder::reset();
            $this->seedSchema('section_failure_models', [
                new SchemaColumn('flag', SchemaColumn::TYPE_BOOL),
                new SchemaColumn('code', SchemaColumn::TYPE_STRING),
            ]);
            SectionFailureModel::$failures = [$failure => true];

            ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);
            $metadata = $this->metadataFor(SectionFailureModel::class);

            $this->assertArrayHasKey('label', $metadata->accessors());
            $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_METHODS));
            foreach ($incompleteSections as $section) {
                $this->assertFalse($metadata->isComplete($section), $failure);
            }

            if ($failure === 'runtime configuration') {
                $this->assertTrue($metadata->isComplete(
                    ModelMetadata::SECTION_SCHEMA
                    | ModelMetadata::SECTION_CASTS
                    | ModelMetadata::SECTION_PRIMARY_KEY,
                ));
                $this->assertTrue($metadata->schema()->has('flag'));
                $this->assertSame('bool', $metadata->casts()['flag']->psalmType->getId());
                $this->assertSame('string', $metadata->casts()['code']->psalmType->getId());
                $this->assertSame(PrimaryKeyType::String, $metadata->primaryKey->type);
                $this->assertFalse($metadata->primaryKey->incrementing);
            }

            if ($failure === 'schema') {
                $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
                $this->assertTrue($metadata->casts()['flag']->psalmType->isNullable());
                $this->assertTrue($metadata->casts()['code']->psalmType->hasMixed());
            }

            if ($failure === 'casts') {
                $this->assertTrue($metadata->schema()->has('flag'));
                $this->assertNull(ModelPropertyHandler::resolveColumnType(
                    $codebase,
                    SectionFailureModel::class,
                    'flag',
                ));
            }

            if ($failure === 'primary key') {
                $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
                $this->assertSame('bool', $metadata->casts()['flag']->psalmType->getId());
            }
        }
    }

    #[Test]
    public function failures_warn_once_and_cached_partial_metadata_is_silent(): void
    {
        $progress = $this->createMock(Progress::class);
        $progress->expects($this->once())
            ->method('warning')
            ->with($this->stringContains("warm-up failed for '" . SectionFailureModel::class . "'"));
        $codebase = $this->makeCodebase($progress);
        $this->registerStorage(SectionFailureModel::class, [HasUuids::class]);
        SectionFailureModel::$failures = ['schema' => true, 'primary key' => true];

        ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);
        ModelMetadataRegistryBuilder::warmUp($codebase, SectionFailureModel::class);

        $metadata = $this->metadataFor(SectionFailureModel::class);
        $this->assertFalse($metadata->isComplete(
            ModelMetadata::SECTION_SCHEMA | ModelMetadata::SECTION_PRIMARY_KEY,
        ));
        $this->assertTrue($metadata->isComplete(
            ModelMetadata::SECTION_METHODS | ModelMetadata::SECTION_CASTS,
        ));
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
    public function set_only_attribute_is_not_a_read_accessor(): void
    {
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(Customer::class);
        // Attribute<never, string>: TGet=never (Attribute::set(...) with no get). Laravel excludes it from
        // getMutatedAttributes(), so it must NOT be a read accessor — else it would override a serialized
        // column/append type or resolve a magic property read. It IS still a mutator (the set side).
        $this->defineAppearingMethod($storage, 'writeOnly', $this->attributeReturn(Type::getNever(), Type::getString()));

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);
        $metadata = $this->metadataFor(Customer::class);

        $this->assertNull($metadata->accessor('write_only'), 'set-only Attribute is not a read accessor');
        $this->assertArrayNotHasKey('writeonly', $metadata->accessors());
        $this->assertInstanceOf(AttributeMutatorInfo::class, $metadata->mutators()['writeonly'] ?? null);
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
        // The abstract path feeds the same storage-derived accessor into knownProperties() — no
        // instance required — so an abstract base exposes a populated, origin-tagged property set too.
        $this->assertTrue(
            $this->metadataFor(AbstractDocument::class)->knownProperties()['referencecode']->has(PropertyOrigin::Accessor),
        );
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        $this->assertArrayHasKey('archived_at', $casts);
        $this->assertSame(CastShape::DateTime, $casts['archived_at']->shape);
        // And the default 'deleted_at' key must not appear for this model.
        $this->assertArrayNotHasKey('deleted_at', $casts);
    }

    #[Test]
    public function bare_collection_cast_string_classifies_as_primitive_not_class_castable(): void
    {
        // The legacy `'collection'` cast string is listed in Laravel's own
        // HasAttributes::$primitiveCastTypes, so Model::isClassCastable() returns false for it —
        // an accessor on the same column must win over it during serialization. #1201 review finding:
        // this used to classify as a distinct (class-castable) shape, wrongly letting the cast win.
        $codebase = $this->makeCodebase();
        $this->registerStorage(CollectionCastVariantsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, CollectionCastVariantsModel::class);

        $metadata = ModelMetadataRegistry::for(CollectionCastVariantsModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

        $shape = $metadata->casts()['legacy_tags']->shape;
        $this->assertSame(CastShape::Primitive, $shape);
        $this->assertFalse($shape->isClassCastable());
    }

    #[Test]
    public function as_collection_class_cast_classifies_as_class_castable(): void
    {
        // AsCollection implements Castable, not CastsAttributes directly (only the instance its
        // castUsing() returns does), so a detector checking CastsAttributes alone misses it and every
        // other framework Castable wrapper — wrongly letting an accessor on the same column win over
        // the class cast during serialization, the inverse of the bare-string bug above.
        //
        // classifyCast() deliberately checks `class_exists($base, false)` (no autoload) to avoid eager
        // file includes during warm-up, so force AsCollection into memory first — matching a real app
        // boot, where the framework class is normally already loaded by the time warm-up reaches it.
        \class_exists(AsCollection::class);

        $codebase = $this->makeCodebase();
        $this->registerStorage(CollectionCastVariantsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, CollectionCastVariantsModel::class);

        $metadata = ModelMetadataRegistry::for(CollectionCastVariantsModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

        $shape = $metadata->casts()['modern_tags']->shape;
        $this->assertSame(CastShape::CustomCastsAttributes, $shape);
        $this->assertTrue($shape->isClassCastable());
    }

    #[Test]
    public function array_form_declared_cast_normalizes_and_keeps_the_casts_section(): void
    {
        // #1281: warm-up left `options` as the raw array Laravel would have collapsed to a string, which
        // TypeErrored inside computeCasts() against buildCastInfo()'s `string $castString` — taking the
        // model's ENTIRE casts section with it. `plain_tags` shares nothing with the array form and
        // disappeared anyway; the section bit below is the regression net, the per-key asserts show the cost.
        \class_exists(AsCollection::class);

        $codebase = $this->makeCodebase();
        $this->registerStorage(ArrayFormCastsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, ArrayFormCastsModel::class);

        $metadata = $this->metadataFor(ArrayFormCastsModel::class);

        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
        // Resolved, not merely retained. The `parameter` assert is what makes this bite: both keys reach the
        // same shape, so shape alone would still pass if the collapse dropped the argument — which is the
        // whole point of the two-element form.
        $this->assertSame(CastShape::CustomCastsAttributes, $metadata->casts()['options']->shape);
        $this->assertSame(Collection::class, $metadata->casts()['options']->parameter);
        // Single-element form collapses to a bare class, carrying no argument.
        $this->assertSame(CastShape::CustomCastsAttributes, $metadata->casts()['single']->shape);
        $this->assertNull($metadata->casts()['single']->parameter);
        // The collateral damage: an unrelated string cast on the same model, back only because the section is.
        $this->assertSame(CastShape::Primitive, $metadata->casts()['plain_tags']->shape);
    }

    #[Test]
    public function non_string_declared_cast_values_are_coerced_to_mixed_and_keep_the_casts_section(): void
    {
        // #1290: ensureCastsAreStringValues()'s `default => $cast` arm passes non-object/array shapes
        // through untouched, so `null_col`/`int_col`/`bool_col`/`float_col`/`nested_col` all reach
        // computeCasts() non-string. Left uncoerced, the first one TypeErrors buildCastInfo()'s
        // `string $castString` and withholds the model's WHOLE casts section — `good_col` included,
        // which is the regression this test's section-completeness assert guards against.
        $codebase = $this->makeCodebase();
        $this->registerStorage(NonStringDeclaredCastsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, NonStringDeclaredCastsModel::class);

        $metadata = $this->metadataFor(NonStringDeclaredCastsModel::class);

        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
        // A genuinely string-valued cast on the same model survives the coercion map untouched.
        $this->assertSame('int|null', $metadata->casts()['good_col']->psalmType->getId());
        // Each non-string value is coerced to 'mixed' rather than dropped: the key stays present
        // (so the section keeps reporting the column at all) and the resolved type stays honest
        // about not knowing the real one.
        $this->assertArrayHasKey('null_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['null_col']->psalmType->hasMixed());
        $this->assertArrayHasKey('int_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['int_col']->psalmType->hasMixed());
        $this->assertArrayHasKey('bool_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['bool_col']->psalmType->hasMixed());
        $this->assertArrayHasKey('float_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['float_col']->psalmType->hasMixed());
        $this->assertArrayHasKey('nested_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['nested_col']->psalmType->hasMixed());
    }

    #[Test]
    public function trait_initializer_writing_raw_cast_value_is_coerced_and_keeps_the_casts_section(): void
    {
        // #1290: a trait initializer writing $this->casts[...] directly (the idiom
        // SoftDeletes::initializeSoftDeletes() itself uses) bypasses mergeCasts()'s normalization
        // entirely, so its value reaches computeCasts() exactly as written by user code.
        $codebase = $this->makeCodebase();
        $this->registerStorage(RawCastInitializerModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, RawCastInitializerModel::class);

        $metadata = $this->metadataFor(RawCastInitializerModel::class);

        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
        $this->assertArrayHasKey('raw_col', $metadata->casts());
        $this->assertTrue($metadata->casts()['raw_col']->psalmType->hasMixed());
    }

    #[Test]
    public function has_ulids_trait_yields_string_primary_key(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(UlidModel::class, [HasUlids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, UlidModel::class);

        $metadata = ModelMetadataRegistry::for(UlidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasUlids);
        $this->assertSame(PrimaryKeyType::String, $metadata->primaryKey->type);
        $this->assertFalse($metadata->primaryKey->incrementing);
        // Same bogus-cast guard as the HasUuids test: the preparer's uniqueIds flip keeps
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasSoftDeletes);
        $this->assertArrayHasKey('deleted_at', $metadata->casts());
        $this->assertSame(CastShape::DateTime, $metadata->casts()['deleted_at']->shape);
    }

    #[Test]
    public function trait_initializer_merged_class_cast_appears_in_casts(): void
    {
        // classifyCast() checks class_exists(..., autoload: false), so force AsArrayObject into memory
        // first, as a real app boot would (mirrors as_collection_class_cast_classifies_as_class_castable).
        \class_exists(AsArrayObject::class);

        $codebase = $this->makeCodebase();
        $this->registerStorage(TraitInitializedConfigModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, TraitInitializedConfigModel::class);

        $metadata = $this->metadataFor(TraitInitializedConfigModel::class);

        // The `meta` cast is merged only by MergesTraitConfig::initializeMergesTraitConfig() — a protected
        // hook the constructor-less warm-up instance skips unless the trait initializer is replayed. Its
        // presence as a class cast is exactly what backs the `meta` append and clears the false positive.
        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_CASTS));
        $this->assertArrayHasKey('meta', $metadata->casts());
        $this->assertTrue($metadata->casts()['meta']->shape->isClassCastable());
    }

    #[Test]
    public function attribute_tagged_trait_initializer_merged_class_cast_appears_in_casts(): void
    {
        // The #[Initialize] attribute and the bootTraits branch reading it arrive in Laravel 12.22; below
        // that the framework ignores the tag, so the replay stays convention-only and `via_attr` is absent
        // (the plugin is correct there — the CI 12.14 floor exercises exactly this).
        if (!\class_exists(\Illuminate\Database\Eloquent\Attributes\Initialize::class)) {
            self::markTestSkipped('The #[Initialize] attribute discovery branch requires Laravel >= 12.22.');
        }

        \class_exists(AsCollection::class);

        $codebase = $this->makeCodebase();
        $this->registerStorage(TraitInitializedConfigModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, TraitInitializedConfigModel::class);

        $metadata = $this->metadataFor(TraitInitializedConfigModel::class);

        // `via_attr` is merged only by SeedsCastViaAttribute::seedViaAttribute() — a `#[Initialize]`-tagged,
        // NON-conventionally-named initializer. It is reachable only through the replay's attribute-discovery
        // branch (bootTraits' second branch); a convention-only replay would miss it and the false positive
        // would return for this shape.
        $this->assertArrayHasKey('via_attr', $metadata->casts());
        $this->assertTrue($metadata->casts()['via_attr']->shape->isClassCastable());
    }

    #[Test]
    public function trait_initializer_merged_fillable_unions_with_class_fillable(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(TraitInitializedConfigModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, TraitInitializedConfigModel::class);

        $metadata = $this->metadataFor(TraitInitializedConfigModel::class);

        // Replaying the initializer must MERGE the trait's mergeFillable() onto the class-level $fillable,
        // never replace or drop it (over-restriction). Order mirrors Laravel's mergeFillable(): the
        // existing class entry first, then the trait-merged one.
        $this->assertTrue($metadata->isComplete(ModelMetadata::SECTION_RUNTIME_CONFIGURATION));
        $this->assertSame(['class_fillable', 'trait_fillable'], $metadata->fillable);
    }

    #[Test]
    public function trait_initializer_setAppends_precedes_attribute_merge(): void
    {
        // #[Appends] exists from Laravel 13.0; below that the attribute is inert and there is nothing to
        // order against.
        if (!\class_exists(\Illuminate\Database\Eloquent\Attributes\Appends::class)) {
            self::markTestSkipped('The #[Appends] attribute requires Laravel >= 13.0.');
        }

        $codebase = $this->makeCodebase();
        $this->registerStorage(AppendsOrderModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, AppendsOrderModel::class);

        $metadata = $this->metadataFor(AppendsOrderModel::class);

        // Runtime construction is the ONLY oracle — never a hardcoded literal: getMethods() ranks the user
        // setAppends(['trait_only']) vs the framework mergeAppends(#[Appends]) differently across PHP versions
        // (both survive on 8.5, the replace wins on 8.4), so runtime getAppends() differs by PHP. The registry
        // must reproduce whatever the running PHP yields, which the getMethods()-order interleave guarantees.
        $this->assertSame((new AppendsOrderModel())->getAppends(), $metadata->appends);
    }

    #[Test]
    public function has_factory_trait_is_flagged(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(Customer::class, [HasFactory::class, SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, Customer::class);

        $metadata = ModelMetadataRegistry::for(Customer::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
        $this->assertTrue($metadata->traits->hasFactory);
    }

    #[Test]
    public function custom_primary_key_column_is_preserved(): void
    {
        $codebase = $this->makeCodebase();
        $this->registerStorage(CustomPkUuidModel::class, [HasUuids::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, CustomPkUuidModel::class);

        $metadata = ModelMetadataRegistry::for(CustomPkUuidModel::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
            $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

        // All attribute-name and relation-method lists preserve the exact case the
        // user declared — Eloquent's isFillable / isGuarded / getHidden / with-loading
        // use case-sensitive string comparisons at runtime.
        $this->assertSame(['Name', 'EMAIL'], $metadata->fillable);
        $this->assertSame(['Id'], $metadata->guarded);
        $this->assertSame(['Password'], $metadata->hidden);
        $this->assertSame(['Name', 'EMAIL'], $metadata->visible);
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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

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
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);

        $casts = $metadata->casts();
        $this->assertArrayHasKey('archived_at', $casts);
        $this->assertTrue(
            $casts['archived_at']->psalmType->isNullable(),
            'A cast on a nullable column must bake |null into the resolved type',
        );
    }

    #[Test]
    public function custom_builder_and_collection_classes_follow_available_attributes(): void
    {
        // WorkOrder declares #[UseEloquentBuilder(WorkOrderBuilder)] and #[CollectedBy(WorkOrderCollection)].
        // The registry records both via the pure resolvers shared with ModelRegistrationHandler.
        $codebase = $this->makeCodebase();
        $this->registerStorage(WorkOrder::class, [SoftDeletes::class]);

        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

        $metadata = ModelMetadataRegistry::for(WorkOrder::class);
        $this->assertInstanceOf(\Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata::class, $metadata);
        if (class_exists(UseEloquentBuilder::class)) {
            $this->assertSame(WorkOrderBuilder::class, $metadata->customBuilder);
        } else {
            $this->assertNull($metadata->customBuilder);
        }

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
    public function known_properties_merge_and_collapse_origins_across_sources(): void
    {
        // `full_name` is named three independent ways — a schema column, a legacy accessor, and a
        // $appends entry — and accessorPropertyKey() collapses every spelling to the same `fullname`
        // key, so the three origins must land on ONE entry rather than fragmenting into
        // full_name / fullname / FullName. The remaining sources (cast, write-only mutator) each
        // contribute their own key + origin.
        $this->seedSchema('scalar_fields_models', [
            new SchemaColumn('id', SchemaColumn::TYPE_INT),
            new SchemaColumn('full_name', SchemaColumn::TYPE_STRING),
        ]);
        $codebase = $this->makeCodebase();
        $storage = $this->registerStorage(ScalarFieldsModel::class);
        $this->defineAppearingMethod($storage, 'getFullNameAttribute', Type::getString());
        $this->defineAppearingMethod($storage, 'setNicknameAttribute', Type::getVoid());

        ModelMetadataRegistryBuilder::warmUp($codebase, ScalarFieldsModel::class);
        $known = $this->metadataFor(ScalarFieldsModel::class)->knownProperties();

        // Triple merge onto one collapsed key — no fragmentation into the un-collapsed spelling.
        $this->assertArrayHasKey('fullname', $known);
        $this->assertTrue($known['fullname']->has(PropertyOrigin::SchemaColumn));
        $this->assertTrue($known['fullname']->has(PropertyOrigin::Accessor));
        $this->assertTrue($known['fullname']->has(PropertyOrigin::Appended));
        $this->assertArrayNotHasKey('full_name', $known);

        // Each remaining source contributes its own origin.
        $this->assertArrayHasKey('id', $known);
        $this->assertTrue($known['id']->has(PropertyOrigin::SchemaColumn));
        $this->assertArrayHasKey('createdat', $known);            // from $casts ['CreatedAt' => ...]
        $this->assertTrue($known['createdat']->has(PropertyOrigin::Cast));
        $this->assertArrayHasKey('nickname', $known);             // write-only setNicknameAttribute()
        $this->assertTrue($known['nickname']->has(PropertyOrigin::Mutator));
    }

    #[Test]
    public function known_properties_excludes_fillable_only_names(): void
    {
        // No schema seeded and no accessor/relation wired: only $casts (CreatedAt) and $appends
        // (FullName) supply names. $fillable (Name / EMAIL) is a guard-list over columns, not an
        // independent name source, so its entries must NOT surface as known properties.
        $codebase = $this->makeCodebase();
        $this->registerStorage(ScalarFieldsModel::class);

        ModelMetadataRegistryBuilder::warmUp($codebase, ScalarFieldsModel::class);
        $known = $this->metadataFor(ScalarFieldsModel::class)->knownProperties();

        $this->assertArrayHasKey('createdat', $known);
        $this->assertArrayHasKey('fullname', $known);
        $this->assertArrayNotHasKey('name', $known);
        $this->assertArrayNotHasKey('email', $known);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeCodebase(?Progress $progress = null): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, $progress ?? new VoidProgress());

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
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods::hasScopeAttribute}, which reads only
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
            relationsData: $relations,
            knownPropertiesData: [],
            completeSections: ModelMetadata::ALL_SECTIONS,
        );
    }
}
