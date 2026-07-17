<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Support;

use App\Models\WorkOrder;
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
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\NodeTypeProvider;
use Psalm\Progress\VoidProgress;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

/**
 * Unit coverage for issue #1293: pluck()'s value/key column resolution now goes through
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler::resolveColumnType()}
 * instead of a `@property`-only lookup, so a column known only from migration schema /
 * casts should narrow too.
 *
 * This can't be a `.phpt`: the type-test harness runs no migrations, so `App\Models\*`
 * fixtures always have empty schemas there (see the identical rationale on
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\ModelSerializationShapeBuilderTest},
 * #1167). Driven instead from a hand-built {@see ModelMetadata} via `overrideForTesting()`
 * against a real {@see Codebase}, exactly like that test — {@see WorkOrder} is reused purely
 * as an autoloadable `is_a(Model::class)` target; its real `@property` docblock never enters
 * play because {@see ClassLikeStorageProvider::create()} allocates a fresh, empty storage
 * rather than scanning the file.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1293
 */
#[CoversClass(ModelPropertyResolver::class)]
final class ModelPropertyResolverPluckSchemaTest extends TestCase
{
    use InitializesPsalmConfigSingleton;

    private ClassLikeStorageProvider $classLikeStorageProvider;

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        ModelMetadataRegistryBuilder::reset();

        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
        // Fresh empty storage: resolveColumnType() checks pseudo_property_get_types first,
        // so an empty storage keeps the test driven purely by the overridden schema/casts —
        // WorkOrder's real @property docblock is never scanned into this provider.
        $this->classLikeStorageProvider->create(WorkOrder::class);
        $this->classLikeStorageProvider->create(SerializedIntStatus::class)->is_enum = true;

        $this->codebase = $this->makeCodebase();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
    }

    #[Test]
    public function value_column_narrows_from_migration_schema_on_an_annotation_less_model(): void
    {
        $this->override(columns: [
            'provider' => $this->col('provider', SchemaColumn::TYPE_STRING),
        ]);

        $result = $this->pluck($this->strArg('provider'));

        $this->assertSame('Illuminate\Support\Collection<int, string>', (string) $result);
    }

    #[Test]
    public function key_column_narrows_from_migration_schema(): void
    {
        $this->override(columns: [
            // Value column deliberately absent from schema — a raw select alias, per #1286 —
            // to isolate that THIS test is exercising the key-side schema lookup, not value.
            'id' => $this->col('id', SchemaColumn::TYPE_INT),
        ]);

        $result = $this->pluck($this->strArg('raw_count'), $this->strArg('id'));

        $this->assertSame('Illuminate\Support\Collection<int, mixed>', (string) $result);
    }

    #[Test]
    public function enum_cast_column_used_as_key_does_not_narrow_tkey(): void
    {
        // The column must exist in schema (columnTypeFromRegistry requires it) AND have a
        // casts() entry — columnTypeFromRegistry returns the cast's psalmType verbatim
        // without re-checking array-key compatibility itself; that guard lives in
        // resolveKeyType()'s isContainedBy() check, which is what this test locks in.
        $this->override(
            columns: [
                'id' => $this->col('id', SchemaColumn::TYPE_INT),
                'status' => $this->col('status', SchemaColumn::TYPE_INT),
            ],
            casts: [
                'status' => new CastInfo(
                    'status',
                    CastShape::BackedEnum,
                    SerializedIntStatus::class,
                    new Union([new TNamedObject(SerializedIntStatus::class)]),
                    null,
                ),
            ],
        );

        $result = $this->pluck($this->strArg('id'), $this->strArg('status'));

        // Value narrows (id: int), key falls back to array-key — the enum object type is not
        // array-key compatible, so it must not leak into TKey.
        $this->assertSame('Illuminate\Support\Collection<array-key, int>', (string) $result);
    }

    #[Test]
    public function nullable_schema_column_used_as_key_falls_back_to_array_key(): void
    {
        $this->override(columns: [
            'id' => $this->col('id', SchemaColumn::TYPE_INT),
            'nickname' => $this->col('nickname', SchemaColumn::TYPE_STRING, nullable: true),
        ]);

        $result = $this->pluck($this->strArg('id'), $this->strArg('nickname'));

        // ?string is not array-key compatible (null isn't a valid array key) — key must fall
        // back to array-key rather than narrowing to a nullable TKey.
        $this->assertSame('Illuminate\Support\Collection<array-key, int>', (string) $result);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function pluck(\PhpParser\Node\Arg $value, ?\PhpParser\Node\Arg $key = null): ?Union
    {
        $args = $key instanceof \PhpParser\Node\Arg ? [$value, $key] : [$value];

        return ModelPropertyResolver::resolvePluckReturnType(
            args: $args,
            templateParams: [new Union([new TNamedObject(WorkOrder::class)])],
            modelTemplateIndex: 0,
            nodeTypeProvider: $this->nodeTypeProvider($args),
            codebase: $this->codebase,
        );
    }

    /** @param list<\PhpParser\Node\Arg> $args */
    private function nodeTypeProvider(array $args): NodeTypeProvider
    {
        $provider = new class implements NodeTypeProvider {
            /** @var \SplObjectStorage<\PhpParser\NodeAbstract, Union> */
            private \SplObjectStorage $types;

            public function __construct()
            {
                $this->types = new \SplObjectStorage();
            }

            #[\Override]
            public function setType(\PhpParser\NodeAbstract $node, Union $type): void
            {
                $this->types[$node] = $type;
            }

            #[\Override]
            public function getType(\PhpParser\NodeAbstract $node): ?Union
            {
                return $this->types->offsetExists($node) ? $this->types[$node] : null;
            }
        };

        foreach ($args as $arg) {
            \assert($arg->value instanceof \PhpParser\Node\Scalar\String_);
            $provider->setType($arg->value, new Union([TLiteralString::make($arg->value->value)]));
        }

        return $provider;
    }

    private function strArg(string $literal): \PhpParser\Node\Arg
    {
        return new \PhpParser\Node\Arg(new \PhpParser\Node\Scalar\String_($literal));
    }

    /** @param non-empty-string $type */
    private function col(string $name, string $type, bool $nullable = false): ColumnInfo
    {
        return new ColumnInfo($name, $type, $nullable, hasDefault: false);
    }

    /**
     * @param array<non-empty-string, ColumnInfo> $columns
     * @param array<non-empty-string, CastInfo>   $casts
     */
    private function override(array $columns, array $casts = []): void
    {
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
            appends: [],
            with: [],
            withCount: [],
            hidden: [],
            visible: [],
            connection: null,
            morphAlias: null,
            customBuilder: null,
            customCollection: null,
            schemaData: new TableSchema($columns),
            castsData: $casts,
            accessorsData: [],
            mutatorsData: [],
            scopesData: [],
            relationsData: [],
            knownPropertiesData: [],
            completeSections: ModelMetadata::ALL_SECTIONS,
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
