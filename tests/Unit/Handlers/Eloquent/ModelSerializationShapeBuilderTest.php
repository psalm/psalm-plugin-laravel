<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelSerializationShapeBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AttributeAccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastShape;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\LegacyAccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\PrimaryKeyType;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TableSchema;
use Psalm\LaravelPlugin\Providers\ModelMetadata\TraitFlags;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;

/**
 * Unit coverage for {@see ModelSerializationShapeBuilder}. The COLUMN shape can't be a `.phpt` (the
 * harness runs no migrations, so app models have empty schemas — #1167); it is driven here from a
 * hand-built {@see ModelMetadata} via `overrideForTesting()` against a real {@see Codebase}. The
 * APPENDS shape needs no schema and is asserted end-to-end in ToArrayShapeTest.phpt.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 */
#[CoversClass(ModelSerializationShapeBuilder::class)]
final class ModelSerializationShapeBuilderTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        ModelMetadataRegistryBuilder::reset();

        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
        // Fresh empty storage for the model: resolveColumnType() reads pseudo_property_get_types
        // from here, so an empty storage keeps the test driven purely by the overridden schema/casts.
        $this->classLikeStorageProvider->create(WorkOrder::class);
        // Storage for the backed-enum target; the backing type is seeded per-test via seedEnumBacking().
        $this->classLikeStorageProvider->create(SerializedIntStatus::class);

        $this->codebase = $this->makeCodebase();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
    }

    #[Test]
    public function builds_an_open_shape_of_columns_with_all_keys_optional(): void
    {
        $this->override(columns: [
            'id' => $this->col('id', SchemaColumn::TYPE_INT),
            'name' => $this->col('name', SchemaColumn::TYPE_STRING),
            'bio' => $this->col('bio', SchemaColumn::TYPE_STRING, nullable: true),
        ]);

        $this->assertSame(
            'array{bio?: null|string, id?: int, name?: string, ...<string, mixed>}',
            (string) $this->build(),
        );
    }

    #[Test]
    public function appends_with_a_typed_accessor_carry_the_accessor_return_type(): void
    {
        $this->override(
            columns: ['id' => $this->col('id', SchemaColumn::TYPE_INT)],
            accessors: ['fullname' => new LegacyAccessorInfo('fullname', Type::getString(), new MethodStorage())],
            appends: ['full_name'],
        );

        // The accessor map is keyed by the separator-collapsed identity, so `full_name` → `fullname`.
        $this->assertSame('array{full_name?: string, id?: int, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function an_append_clashing_with_a_column_takes_the_accessor_type(): void
    {
        // Laravel adds appends after the attribute bag, so on a name clash the accessor value wins.
        $this->override(
            columns: ['name' => $this->col('name', SchemaColumn::TYPE_STRING)],
            accessors: ['name' => new LegacyAccessorInfo('name', Type::getInt(), new MethodStorage())],
            appends: ['name'],
        );

        $this->assertSame('array{name?: int, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function appends_without_a_resolvable_accessor_are_mixed(): void
    {
        $this->override(
            columns: ['id' => $this->col('id', SchemaColumn::TYPE_INT)],
            appends: ['mystery'],
        );

        $this->assertSame('array{id?: int, mystery?: mixed, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function hidden_keys_are_dropped(): void
    {
        $this->override(
            columns: [
                'id' => $this->col('id', SchemaColumn::TYPE_INT),
                'password' => $this->col('password', SchemaColumn::TYPE_STRING),
            ],
            hidden: ['password'],
        );

        $this->assertSame('array{id?: int, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function visible_limits_the_shape_to_the_allow_list(): void
    {
        $this->override(
            columns: [
                'id' => $this->col('id', SchemaColumn::TYPE_INT),
                'name' => $this->col('name', SchemaColumn::TYPE_STRING),
                'secret' => $this->col('secret', SchemaColumn::TYPE_STRING),
            ],
            visible: ['id', 'name'],
        );

        $this->assertSame('array{id?: int, name?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function hidden_wins_when_a_key_is_both_visible_and_hidden(): void
    {
        // getArrayableItems intersects $visible first, then subtracts $hidden — an overlap is removed.
        $this->override(
            columns: [
                'id' => $this->col('id', SchemaColumn::TYPE_INT),
                'secret' => $this->col('secret', SchemaColumn::TYPE_STRING),
            ],
            hidden: ['secret'],
            visible: ['id', 'secret'],
        );

        $this->assertSame('array{id?: int, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_datetime_cast_serializes_to_a_string_not_carbon(): void
    {
        // The cast reads as Carbon (psalmType), but attributesToArray() serializes it to an ISO string.
        $carbonNullable = new Union([new TNamedObject(Carbon::class), new TNull()]);

        $this->override(
            columns: ['published_at' => $this->col('published_at', SchemaColumn::TYPE_STRING, nullable: true)],
            casts: ['published_at' => new CastInfo('published_at', CastShape::DateTime, null, $carbonNullable, null)],
        );

        $this->assertSame('array{published_at?: null|string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_backed_int_enum_cast_serializes_to_its_backing_int(): void
    {
        $this->seedEnumBacking('int');

        $this->override(
            columns: ['status' => $this->col('status', SchemaColumn::TYPE_STRING)],
            casts: ['status' => $this->enumCast('status')],
        );

        $this->assertSame('array{status?: int, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_backed_string_enum_cast_serializes_to_its_backing_string(): void
    {
        $this->seedEnumBacking('string');

        $this->override(
            columns: ['status' => $this->col('status', SchemaColumn::TYPE_STRING)],
            casts: ['status' => $this->enumCast('status')],
        );

        $this->assertSame('array{status?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function an_unresolved_enum_backing_falls_back_to_the_read_type(): void
    {
        // enum_type is left unseeded → backedEnumValueType() bails → the column keeps its read-type
        // (here the cast psalmType, the enum object), rather than collapsing to int|string.
        $this->override(
            columns: ['status' => $this->col('status', SchemaColumn::TYPE_STRING)],
            casts: ['status' => $this->enumCast('status')],
        );

        $this->assertSame(
            'array{status?: ' . SerializedIntStatus::class . ', ...<string, mixed>}',
            (string) $this->build(),
        );
    }

    #[Test]
    public function a_column_backed_by_a_legacy_accessor_takes_the_accessor_type(): void
    {
        // Laravel runs mutators before casts and skips the cast for a mutated key, so a real column with
        // an accessor serializes as the accessor's type — here the int column reads back as the
        // accessor's string, not the schema int.
        $this->override(
            columns: ['score' => $this->col('score', SchemaColumn::TYPE_INT)],
            accessors: ['score' => new LegacyAccessorInfo('score', Type::getString(), new MethodStorage())],
        );

        $this->assertSame('array{score?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_column_accessor_wins_over_a_divergent_cast(): void
    {
        // The accessor short-circuits before the cast is consulted (accessor > date/enum cast > schema):
        // the backed-enum cast would serialize to its int backing, but the accessor's string wins.
        $this->override(
            columns: ['status' => $this->col('status', SchemaColumn::TYPE_STRING)],
            casts: ['status' => $this->enumCast('status')],
            accessors: ['status' => new LegacyAccessorInfo('status', Type::getString(), new MethodStorage())],
        );

        $this->assertSame('array{status?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_column_backed_by_a_modern_accessor_takes_the_accessor_type(): void
    {
        // Modern Attribute accessors flow through the same accessorFor()/serializedAppendType() path as
        // legacy ones; for a scalar return the serialized type equals the read type.
        $this->override(
            columns: ['ratio' => $this->col('ratio', SchemaColumn::TYPE_INT)],
            accessors: ['ratio' => new AttributeAccessorInfo('ratio', Type::getString(), new MethodStorage(), hasMutator: false)],
        );

        $this->assertSame('array{ratio?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function an_unhinted_column_accessor_yields_mixed(): void
    {
        // An accessor with no resolvable return type makes the present key mixed (sound: the runtime
        // value is the accessor's, which is genuinely unknown) rather than keeping the precise schema type.
        $this->override(
            columns: ['data' => $this->col('data', SchemaColumn::TYPE_INT)],
            accessors: ['data' => new LegacyAccessorInfo('data', Type::getMixed(), new MethodStorage())],
        );

        $this->assertSame('array{data?: mixed, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_class_cast_wins_over_an_accessor_on_the_same_column(): void
    {
        // mutateAttributeForArray() applies isClassCastable() BEFORE the accessor, so a column with a
        // CastsAttributes/Castable cast AND an accessor serializes as the cast (we keep the read type),
        // not the accessor. Here the accessor int is suppressed; the column keeps its schema string.
        $this->override(
            columns: ['foo' => $this->col('foo', SchemaColumn::TYPE_STRING)],
            casts: ['foo' => new CastInfo('foo', CastShape::CustomCastsAttributes, null, Type::getString(), null)],
            accessors: ['foo' => new LegacyAccessorInfo('foo', Type::getInt(), new MethodStorage())],
        );

        $this->assertSame('array{foo?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function no_columns_and_no_appends_defers_to_the_stub(): void
    {
        // Nothing names a key — no parsed columns (migrations disabled) and no $appends. The builder
        // defers to the stub's array<string, mixed> rather than emitting an empty shape.
        $this->override(columns: []);

        $this->assertNull($this->build());
    }

    #[Test]
    public function appends_emit_a_shape_even_without_columns(): void
    {
        // The schema-empty case is NOT a hard gate: $appends are always serialized, so they name keys
        // even when migrations are disabled. The shape stays OPEN, so the unseen columns fall through
        // to mixed. This pins the production behavior that ToArrayShapeTest.phpt asserts end-to-end.
        $this->override(
            columns: [],
            accessors: ['fullname' => new LegacyAccessorInfo('fullname', Type::getString(), new MethodStorage())],
            appends: ['full_name'],
        );

        $this->assertSame('array{full_name?: string, ...<string, mixed>}', (string) $this->build());
    }

    #[Test]
    public function a_shape_with_no_surviving_keys_defers_to_the_stub(): void
    {
        $this->override(
            columns: ['id' => $this->col('id', SchemaColumn::TYPE_INT)],
            hidden: ['id'],
        );

        $this->assertNull($this->build());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function build(): ?Union
    {
        $metadata = ModelMetadataRegistry::for(WorkOrder::class);
        $this->assertInstanceOf(ModelMetadata::class, $metadata);

        return ModelSerializationShapeBuilder::build($this->codebase, WorkOrder::class, $metadata);
    }

    /** @param non-empty-string $type */
    private function col(string $name, string $type, bool $nullable = false): ColumnInfo
    {
        return new ColumnInfo($name, $type, $nullable, hasDefault: false);
    }

    /** @param non-empty-string $column */
    private function enumCast(string $column): CastInfo
    {
        $enumType = new Union([new TNamedObject(SerializedIntStatus::class)]);

        return new CastInfo($column, CastShape::BackedEnum, SerializedIntStatus::class, $enumType, null);
    }

    private function seedEnumBacking(string $backingType): void
    {
        $this->classLikeStorageProvider->get(SerializedIntStatus::class)->enum_type = $backingType;
    }

    /**
     * @param array<non-empty-string, ColumnInfo>             $columns
     * @param array<non-empty-string, CastInfo>               $casts
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessors
     * @param list<non-empty-string>                          $appends
     * @param list<non-empty-string>                          $hidden
     * @param list<non-empty-string>                          $visible
     */
    private function override(
        array $columns,
        array $casts = [],
        array $accessors = [],
        array $appends = [],
        array $hidden = [],
        array $visible = [],
    ): void {
        $metadata = new ModelMetadata(
            fqcn: WorkOrder::class,
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
            appends: $appends,
            with: [],
            withCount: [],
            hidden: $hidden,
            visible: $visible,
            connection: null,
            morphAlias: null,
            customBuilder: null,
            customCollection: null,
            schemaData: new TableSchema($columns),
            castsData: $casts,
            accessorsData: $accessors,
            mutatorsData: [],
            scopesData: [],
            relationsData: [],
            knownPropertiesData: [],
        );

        ModelMetadataRegistryBuilder::overrideForTesting(WorkOrder::class, $metadata);
    }

    private function makeCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, new VoidProgress());

        return $codebase;
    }
}
