<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CastShape;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TableSchema;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\TraitFlags;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnTypes;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ScalarFieldsModel;

#[CoversClass(PivotColumnTypes::class)]
final class PivotColumnTypesTest extends TestCase
{
    private ClassLikeStorageProvider $storageProvider;

    /** @var list<class-string> */
    private array $registeredStorage = [];

    #[\Override]
    protected function setUp(): void
    {
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('role', SchemaColumn::TYPE_STRING));
        $table->setColumn(new SchemaColumn('level', SchemaColumn::TYPE_INT, nullable: true));
        $table->setColumn(new SchemaColumn('created_at', SchemaColumn::TYPE_STRING));
        $table->setColumn(new SchemaColumn('updated_at', SchemaColumn::TYPE_STRING, nullable: true));
        $table->setColumn(new SchemaColumn('joined_at', SchemaColumn::TYPE_STRING));

        $schema = new SchemaAggregator();
        $schema->setTable('team_user', $table);
        SchemaStateProvider::setSchema($schema);
        $this->storageProvider = new ClassLikeStorageProvider();
        $this->registeredStorage = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->registeredStorage as $class) {
            $this->storageProvider->remove($class);
        }

        ModelMetadataRegistryBuilder::reset();
        SchemaStateProvider::reset();
    }

    #[Test]
    public function schema_columns_use_the_model_property_nullable_mapping(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['role', 'level'], 'team_user', null, Pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['role']));
        $this->assertSame(['int', 'null'], $this->atomKeys($types['level']));
    }

    #[Test]
    public function unknown_table_or_column_reads_mixed(): void
    {
        $codebase = $this->codebase();
        $unknownTable = PivotColumnTypes::resolve($codebase, ['role'], 'no_such_table', null, Pivot::class);
        $noTable = PivotColumnTypes::resolve($codebase, ['role'], null, null, Pivot::class);
        $unknownColumn = PivotColumnTypes::resolve($codebase, ['nope'], 'team_user', null, Pivot::class);

        $this->assertTrue($unknownTable['role']->isMixed());
        $this->assertTrue($noTable['role']->isMixed());
        $this->assertTrue($unknownColumn['nope']->isMixed());
    }

    #[Test]
    public function parent_timestamp_columns_read_as_carbon_once_created_at_is_selected(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', ScalarFieldsModel::class, Pivot::class);

        // Schema nullability still applies: created_at is NOT NULL, updated_at is nullable.
        $this->assertSame(['Illuminate\Support\Carbon'], $this->atomKeys($types['created_at']));
        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function date_cast_column_with_unknown_schema_is_nullable_carbon(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], null, ScalarFieldsModel::class, Pivot::class);

        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['created_at']));
        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function updated_at_alone_is_not_date_cast(): void
    {
        // Runtime sets `$timestamps` only when the created-at column is selected.
        $types = PivotColumnTypes::resolve($this->codebase(), ['updated_at'], 'team_user', null, Pivot::class);

        $this->assertSame(['null', 'string'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function custom_named_timestamp_column_keeps_its_schema_type(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'joined_at'], 'team_user', null, Pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['joined_at']));
    }

    #[Test]
    public function duplicate_columns_are_collapsed(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['role', 'level', 'role'], 'team_user', null, Pivot::class);

        $this->assertSame(['role', 'level'], \array_keys($types));
    }

    #[Test]
    public function no_columns_resolve_to_nothing(): void
    {
        $this->assertSame([], PivotColumnTypes::resolve($this->codebase(), [], 'team_user', null, Pivot::class));
    }

    #[Test]
    public function parent_timestamp_names_are_unknown_without_an_instantiable_parent(): void
    {
        $this->assertNull(PivotColumnTypes::parentTimestampNames(null));
        // Abstract: constructor-less instantiation throws.
        $this->assertNull(PivotColumnTypes::parentTimestampNames(Model::class));
    }

    #[Test]
    public function parent_timestamp_names_default_for_a_model_without_overrides(): void
    {
        $this->assertSame(['created_at', 'updated_at'], PivotColumnTypes::parentTimestampNames(ScalarFieldsModel::class));
    }

    #[Test]
    public function parent_timestamp_names_follow_model_overrides_and_keep_null_positions(): void
    {
        $custom = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = null;
        };
        $withoutCreatedAt = new class extends Model {
            public const CREATED_AT = null;

            public const UPDATED_AT = 'changed_at';
        };

        $this->assertSame(['made_at', null], PivotColumnTypes::parentTimestampNames($custom::class));
        $this->assertSame([null, 'changed_at'], PivotColumnTypes::parentTimestampNames($withoutCreatedAt::class));
    }

    #[Test]
    public function plain_pivot_gate_is_the_literal_created_at_not_the_parents_name(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };

        // `Pivot::fromAttributes()` evaluates `hasTimestampAttributes()` before the parent is known.
        $types = PivotColumnTypes::resolve($this->codebase(), ['made_at', 'changed_at'], 'team_user', $parent::class, Pivot::class);

        $this->assertTrue($types['made_at']->isMixed());
        $this->assertTrue($types['changed_at']->isMixed());
    }

    #[Test]
    public function plain_morph_pivot_uses_the_same_literal_gate(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };

        $types = PivotColumnTypes::resolve($this->codebase(), ['made_at', 'changed_at'], null, $parent::class, MorphPivot::class);

        $this->assertTrue($types['made_at']->isMixed());
        $this->assertTrue($types['changed_at']->isMixed());
    }

    #[Test]
    public function plain_pivot_casts_the_parents_names_once_literal_created_at_is_selected(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = null;
        };

        // The parent has no created-at column, yet the literal `created_at` still opens the gate:
        // only the parent's non-null updated-at column is then cast.
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', $parent::class, Pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['created_at']));
        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function custom_pivot_gate_is_the_parents_created_at_column(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };
        $pivot = new class extends Pivot {};

        // `using()` classes are built via `fromRawAttributes()`, after the parent is set.
        $types = PivotColumnTypes::resolve($this->codebase(), ['made_at', 'changed_at'], 'team_user', $parent::class, $pivot::class);

        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['made_at']));
        $this->assertSame(['Illuminate\Support\Carbon', 'null'], $this->atomKeys($types['changed_at']));
    }

    #[Test]
    public function custom_pivot_literal_created_at_does_not_open_the_gate_for_a_renamed_parent(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = 'made_at';

            public const UPDATED_AT = 'changed_at';
        };
        $pivot = new class extends Pivot {};

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', $parent::class, $pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['created_at']));
        $this->assertSame(['null', 'string'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function parent_without_created_at_never_opens_the_custom_pivot_gate(): void
    {
        $parent = new class extends Model {
            public const CREATED_AT = null;
        };
        $pivot = new class extends Pivot {};

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', $parent::class, $pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['created_at']));
        $this->assertSame(['null', 'string'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function custom_pivot_declared_column_type_wins_over_the_table_schema(): void
    {
        $pivot = new class extends Pivot {};
        $this->storageFor($pivot::class)->pseudo_property_get_types['$role'] = Type::getInt();

        $types = PivotColumnTypes::resolve($this->codebase(), ['role', 'level'], 'team_user', null, $pivot::class);

        $this->assertSame(['int'], $this->atomKeys($types['role']));
        // No declaration for `level`: falls through to the relation table's schema.
        $this->assertSame(['int', 'null'], $this->atomKeys($types['level']));
    }

    #[Test]
    public function declared_type_wins_over_the_date_cast_of_a_custom_pivot(): void
    {
        $pivot = new class extends Pivot {};
        $this->storageFor($pivot::class)->pseudo_property_get_types['$created_at'] = Type::getString();

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at'], 'team_user', ScalarFieldsModel::class, $pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['created_at']));
    }

    #[Test]
    public function explicit_cast_of_a_custom_pivot_wins_over_the_date_cast(): void
    {
        $pivot = new class extends Pivot {};
        $this->storeCasts($pivot::class, [
            'created_at' => new Union([new TNamedObject(\Carbon\CarbonImmutable::class)]),
            'updated_at' => Type::getString(),
        ]);

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', ScalarFieldsModel::class, $pivot::class);

        $this->assertSame(['Carbon\CarbonImmutable'], $this->atomKeys($types['created_at']));
        $this->assertSame(['string'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function declared_property_wins_over_an_explicit_cast_of_a_custom_pivot(): void
    {
        $pivot = new class extends Pivot {};
        $this->storageFor($pivot::class)->pseudo_property_get_types['$created_at'] = Type::getInt();
        $this->storeCasts($pivot::class, ['created_at' => Type::getString()]);

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at'], 'team_user', ScalarFieldsModel::class, $pivot::class);

        $this->assertSame(['int'], $this->atomKeys($types['created_at']));
    }

    #[Test]
    public function cast_inventory_that_is_not_complete_is_ignored(): void
    {
        $pivot = new class extends Pivot {};
        $this->storeCasts($pivot::class, ['created_at' => Type::getString()], ModelMetadata::ALL_SECTIONS & ~ModelMetadata::SECTION_CASTS);

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at'], 'team_user', ScalarFieldsModel::class, $pivot::class);

        $this->assertSame(['Illuminate\Support\Carbon'], $this->atomKeys($types['created_at']));
    }

    #[Test]
    public function date_cast_wins_over_the_registered_schema_and_the_table_schema_of_a_custom_pivot(): void
    {
        $pivot = new class extends Pivot {};
        $this->storeCasts($pivot::class, [], schema: ['created_at' => new ColumnInfo('created_at', SchemaColumn::TYPE_INT, false, false)]);

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at'], 'team_user', ScalarFieldsModel::class, $pivot::class);

        $this->assertSame(['Illuminate\Support\Carbon'], $this->atomKeys($types['created_at']));
    }

    #[Test]
    public function registered_schema_of_a_custom_pivot_wins_over_the_table_schema_for_non_date_columns(): void
    {
        $pivot = new class extends Pivot {};
        $this->storeCasts($pivot::class, [], schema: ['role' => new ColumnInfo('role', SchemaColumn::TYPE_INT, false, false)]);

        $types = PivotColumnTypes::resolve($this->codebase(), ['role'], 'team_user', null, $pivot::class);

        $this->assertSame(['int'], $this->atomKeys($types['role']));
    }

    #[Test]
    public function unknown_parent_never_forces_carbon_on_a_plain_pivot(): void
    {
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], 'team_user', null, Pivot::class);

        $this->assertSame(['string'], $this->atomKeys($types['created_at']));
        $this->assertSame(['null', 'string'], $this->atomKeys($types['updated_at']));
    }

    #[Test]
    public function unknown_parent_never_forces_carbon_on_a_custom_pivot(): void
    {
        $pivot = new class extends Pivot {};

        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], null, null, $pivot::class);

        $this->assertTrue($types['created_at']->isMixed());
        $this->assertTrue($types['updated_at']->isMixed());
    }

    #[Test]
    public function abstract_parent_never_forces_carbon(): void
    {
        // Model is abstract: no constructor-less instance, so the parent's timestamp names are unknown.
        $types = PivotColumnTypes::resolve($this->codebase(), ['created_at', 'updated_at'], null, Model::class, Pivot::class);

        $this->assertTrue($types['created_at']->isMixed());
        $this->assertTrue($types['updated_at']->isMixed());
    }

    #[Test]
    public function custom_pivot_without_declaration_or_table_reads_mixed(): void
    {
        $pivot = new class extends Pivot {};

        $types = PivotColumnTypes::resolve($this->codebase(), ['role'], null, null, $pivot::class);

        $this->assertTrue($types['role']->isMixed());
    }

    #[Test]
    public function refine_adds_an_object_shape_to_a_named_pivot(): void
    {
        $slot = new Union([new TNamedObject(Pivot::class)]);

        $refined = PivotColumnTypes::refine($slot, ['role' => Type::getString()]);

        $this->assertInstanceOf(Union::class, $refined);
        $this->assertSame(['role'], \array_keys($this->shape($refined)->properties));
        $this->assertSame(Pivot::class, $this->pivotAtom($refined)->value);
    }

    #[Test]
    public function refine_keeps_existing_keys_and_appends_new_ones(): void
    {
        $slot = new Union([
            (new TNamedObject(Pivot::class))->addIntersectionType(new TObjectWithProperties(['role' => Type::getInt()])),
        ]);

        $refined = PivotColumnTypes::refine($slot, ['role' => Type::getString(), 'level' => Type::getMixed()]);

        $this->assertInstanceOf(Union::class, $refined);
        $properties = $this->shape($refined)->properties;
        $this->assertSame(['role', 'level'], \array_keys($properties));
        $this->assertSame('int', (string) $properties['role']);
        // The old shape is replaced, not stacked next to the merged one.
        $this->assertCount(1, $this->pivotAtom($refined)->extra_types);
    }

    #[Test]
    public function refine_preserves_is_static_and_other_intersection_members(): void
    {
        $other = new TNamedObject(\Countable::class);
        $slot = new Union([
            new TNamedObject(Pivot::class, true, false, [$other->getKey() => $other]),
        ]);

        $refined = PivotColumnTypes::refine($slot, ['role' => Type::getString()]);

        $this->assertInstanceOf(Union::class, $refined);
        $pivot = $this->pivotAtom($refined);
        $this->assertTrue($pivot->is_static);
        $this->assertArrayHasKey(\Countable::class, $pivot->extra_types);
    }

    #[Test]
    public function refine_with_no_columns_returns_the_slot_unchanged(): void
    {
        $slot = new Union([new TNamedObject(Pivot::class)]);

        $this->assertSame($slot, PivotColumnTypes::refine($slot, []));
    }

    #[Test]
    public function refine_declines_a_slot_that_is_not_a_single_named_object(): void
    {
        $columns = ['role' => Type::getString()];

        $this->assertNull(PivotColumnTypes::refine(Type::getMixed(), $columns));
        $this->assertNull(PivotColumnTypes::refine(
            new Union([new TNamedObject(Pivot::class), new TNamedObject(\stdClass::class)]),
            $columns,
        ));
    }

    /**
     * Only `classlike_storage_provider` is read by the custom-pivot declaration lookup, so a constructor-less
     * Codebase is enough (the same shortcut the registry tests use).
     */
    private function codebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->storageProvider;

        return $codebase;
    }

    /**
     * Registers the pivot class in the metadata registry with the given explicit casts (column => read type),
     * as the warm-up does for every scanned model.
     *
     * @param class-string<Model> $class
     * @param array<non-empty-string, Union> $castTypes
     * @param array<non-empty-string, ColumnInfo> $schema
     */
    private function storeCasts(string $class, array $castTypes, int $sections = ModelMetadata::ALL_SECTIONS, array $schema = []): void
    {
        $casts = [];
        foreach ($castTypes as $column => $type) {
            $casts[$column] = new CastInfo($column, CastShape::Primitive, null, $type, null);
        }

        ModelMetadataRegistryBuilder::overrideForTesting($class, new ModelMetadata(
            fqcn: $class,
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
            schemaData: new TableSchema($schema),
            castsData: $casts,
            accessorsData: [],
            mutatorsData: [],
            scopesData: [],
            relationsData: [],
            knownPropertiesData: [],
            completeSections: $sections,
        ));
    }

    /**
     * @param class-string $class
     */
    private function storageFor(string $class): ClassLikeStorage
    {
        $this->registeredStorage[] = $class;

        return $this->storageProvider->create($class);
    }

    /**
     * @return list<string> sorted atomic ids, so assertions do not depend on union ordering
     */
    private function atomKeys(Union $type): array
    {
        $keys = \array_keys($type->getAtomicTypes());
        \sort($keys);

        return $keys;
    }

    private function pivotAtom(Union $type): TNamedObject
    {
        $atom = $type->getSingleAtomic();
        $this->assertInstanceOf(TNamedObject::class, $atom);

        return $atom;
    }

    private function shape(Union $type): TObjectWithProperties
    {
        foreach ($this->pivotAtom($type)->extra_types as $extra) {
            if ($extra instanceof TObjectWithProperties) {
                return $extra;
            }
        }

        self::fail('no object shape on the refined pivot');
    }
}
