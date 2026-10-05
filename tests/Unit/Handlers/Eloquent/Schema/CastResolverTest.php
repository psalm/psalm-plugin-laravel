<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Illuminate\Database\Eloquent\Casts\ArrayObject as EloquentArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Stringable as IlluminateStringable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\Type;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverBackedEnum;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverPartialLoadEnum;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverThrowsOnLoadEnum;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverUnitEnum;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverUnscannedEnum;

/**
 * Unit-tests the {@see CastResolver} branches that don't require a fully-wired
 * {@see Codebase}: scalars, encrypted recursion, enum strings, framework Castable
 * casts, and the cast-with-parameters colon-stripping.
 *
 * The CastsAttributes / Castable / CastsInboundAttributes branches reflect on the
 * cast class through `Codebase::methodExists` / `getMethodReturnType` which require
 * a real `Psalm\Internal\Codebase\Methods` instance — exercised end-to-end by the
 * Type and Application layers. Sibling pattern: {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\ResolveColumnTypeTest}.
 */
#[CoversClass(CastResolver::class)]
final class CastResolverTest extends TestCase
{
    /** @var list<string> */
    private const STORED = [CastResolverBackedEnum::class, CastResolverUnitEnum::class, CastResolverThrowsOnLoadEnum::class];

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        // Partial Codebase: the storage CastResolver reads its class lookups from first (#1652), and the
        // class registry for `class_alias()` names. Fixtures are registered the way Psalm's scan would.
        $storageProvider = new ClassLikeStorageProvider();
        $storageProvider->create(CastResolverBackedEnum::class)->is_enum = true;
        $storageProvider->create(CastResolverUnitEnum::class)->is_enum = true;

        $this->codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $this->codebase->classlike_storage_provider = $storageProvider;
        $this->codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $storageProvider = new ClassLikeStorageProvider();
        foreach (self::STORED as $class) {
            $storageProvider->remove($class);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function scalarCastProvider(): iterable
    {
        yield 'int' => ['int', 'int'];
        yield 'integer alias' => ['integer', 'int'];
        yield 'timestamp' => ['timestamp', 'int'];
        yield 'float' => ['float', 'float'];
        yield 'real alias' => ['real', 'float'];
        yield 'double alias' => ['double', 'float'];
        yield 'string' => ['string', 'string'];
        yield 'decimal' => ['decimal', 'string'];
        yield 'hashed' => ['hashed', 'string'];
        yield 'bool' => ['bool', 'bool'];
        yield 'boolean alias' => ['boolean', 'bool'];
        yield 'array' => ['array', 'array<array-key, mixed>'];
        yield 'json alias' => ['json', 'array<array-key, mixed>'];
        yield 'collection' => ['collection', \Illuminate\Support\Collection::class];
        yield 'date' => ['date', \Illuminate\Support\Carbon::class];
        yield 'datetime' => ['datetime', \Illuminate\Support\Carbon::class];
        yield 'custom_datetime' => ['custom_datetime', \Illuminate\Support\Carbon::class];
        yield 'immutable_date' => ['immutable_date', \Carbon\CarbonImmutable::class];
        yield 'immutable_datetime' => ['immutable_datetime', \Carbon\CarbonImmutable::class];
        yield 'immutable_custom_datetime' => ['immutable_custom_datetime', \Carbon\CarbonImmutable::class];
    }

    #[Test]
    #[DataProvider('scalarCastProvider')]
    public function it_resolves_scalar_casts(string $cast, string $expected): void
    {
        $union = CastResolver::resolve($this->codebase, $cast, nullable: false);

        $this->assertSame($expected, (string) $union);
        $this->assertFalse($union->isNullable(), 'Non-nullable column must not produce a nullable type');
    }

    #[Test]
    #[DataProvider('scalarCastProvider')]
    public function it_adds_null_for_nullable_columns(string $cast, string $_expected): void
    {
        $union = CastResolver::resolve($this->codebase, $cast, nullable: true);

        $this->assertTrue($union->isNullable(), "Nullable column must produce a nullable type for {$cast}");
    }

    #[Test]
    public function it_resolves_decimal_with_parameter(): void
    {
        // `decimal:2` — parameters after colon must be stripped for scalar matching.
        $union = CastResolver::resolve($this->codebase, 'decimal:2', nullable: false);

        $this->assertSame('string', (string) $union);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function encryptedCastProvider(): iterable
    {
        // Laravel's HasAttributes::isEncryptedCastable accepts only these four suffixes.
        yield 'encrypted:array' => ['encrypted:array', 'array<array-key, mixed>'];
        yield 'encrypted:json' => ['encrypted:json', 'array<array-key, mixed>'];
        yield 'encrypted:collection' => ['encrypted:collection', \Illuminate\Support\Collection::class];
        yield 'encrypted:object' => ['encrypted:object', 'object{}'];
    }

    #[Test]
    #[DataProvider('encryptedCastProvider')]
    public function it_recurses_through_encrypted_prefix(string $cast, string $expected): void
    {
        $union = CastResolver::resolve($this->codebase, $cast, nullable: false);

        $this->assertSame($expected, (string) $union);
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unsupported_encrypted_suffix(): void
    {
        // Laravel does NOT support `encrypted:int`, `encrypted:string`, etc. — only the
        // four JSON-castable suffixes above. Anything else is treated by Laravel as a
        // (non-existent) custom class cast and falls through to a no-op.
        $union = CastResolver::resolve($this->codebase, 'encrypted:int', nullable: false);

        $this->assertSame('mixed', (string) $union);
    }

    #[Test]
    public function it_resolves_enum_prefix_to_named_object(): void
    {
        $union = CastResolver::resolve(
            $this->codebase,
            'enum:' . CastResolverBackedEnum::class,
            nullable: false,
        );

        $this->assertSame(CastResolverBackedEnum::class, (string) $union);
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unknown_enum_class_after_prefix(): void
    {
        $union = CastResolver::resolve(
            $this->codebase,
            'enum:NonExistent\\EnumClass',
            nullable: false,
        );

        $this->assertSame('mixed', (string) $union);
    }

    #[Test]
    public function it_resolves_plain_backed_enum_class(): void
    {
        // Plain enum class name in `$casts` (without `enum:` prefix).
        $union = CastResolver::resolve($this->codebase, CastResolverBackedEnum::class, nullable: false);

        $this->assertSame(CastResolverBackedEnum::class, (string) $union);
    }

    #[Test]
    public function it_resolves_plain_unit_enum_class(): void
    {
        $union = CastResolver::resolve($this->codebase, CastResolverUnitEnum::class, nullable: false);

        $this->assertSame(CastResolverUnitEnum::class, (string) $union);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function frameworkCastProvider(): iterable
    {
        $arrayObject = EloquentArrayObject::class . '<array-key, mixed>|null';
        $collection = IlluminateCollection::class . '<array-key, mixed>|null';
        $stringable = IlluminateStringable::class . '|null';

        yield 'AsArrayObject' => [AsArrayObject::class, $arrayObject];
        yield 'AsEncryptedArrayObject' => [AsEncryptedArrayObject::class, $arrayObject];
        yield 'AsCollection' => [AsCollection::class, $collection];
        yield 'AsEncryptedCollection' => [AsEncryptedCollection::class, $collection];
        yield 'AsStringable' => [AsStringable::class, $stringable];
    }

    /**
     * Framework Castable casts always include null (their castUsing's get() returns null on
     * malformed input) regardless of column nullability — matches Larastan's behaviour.
     */
    #[Test]
    #[DataProvider('frameworkCastProvider')]
    public function it_hardcodes_framework_castable_types(string $castClass, string $expected): void
    {
        $union = CastResolver::resolve($this->codebase, $castClass, nullable: false);

        $this->assertSame($expected, (string) $union);
    }

    #[Test]
    public function it_resolves_framework_cast_with_parameters(): void
    {
        // `AsCollection:CustomCollection` — colon-stripping must apply to the case-preserving
        // class name path too, not just the lowercased base. Regression for #930 fix.
        $union = CastResolver::resolve($this->codebase, AsCollection::class . ':CustomCollection', nullable: false);

        $this->assertSame(IlluminateCollection::class . '<array-key, mixed>|null', (string) $union);
    }

    #[Test]
    public function it_resolves_enum_with_parameters(): void
    {
        // `EnumClass:fallback` shape — colon-stripping for plain enum classes.
        $union = CastResolver::resolve(
            $this->codebase,
            CastResolverBackedEnum::class . ':default',
            nullable: false,
        );

        $this->assertSame(CastResolverBackedEnum::class, (string) $union);
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unknown_cast(): void
    {
        $union = CastResolver::resolve($this->codebase, 'NonExistent\\CastClass', nullable: false);

        $this->assertSame('mixed', (string) $union);
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unknown_cast_with_parameters(): void
    {
        $union = CastResolver::resolve($this->codebase, 'NonExistent\\CastClass:arg1,arg2', nullable: false);

        $this->assertSame('mixed', (string) $union);
    }

    #[Test]
    public function it_returns_mixed_for_bare_encrypted(): void
    {
        // Bare `encrypted` (no inner cast) → mixed per Laravel runtime semantics.
        $union = CastResolver::resolve($this->codebase, 'encrypted', nullable: false);

        $this->assertSame('mixed', (string) $union);
    }

    /**
     * Object casts decode JSON into an stdClass-ish shape. We surface a typed-object union
     * rather than mixed to give callers a usable type.
     */
    #[Test]
    public function it_resolves_object_cast(): void
    {
        $union = CastResolver::resolve($this->codebase, 'object', nullable: false);

        $this->assertFalse($union->isMixed());
        $this->assertSame('object{}', (string) $union);
    }

    /**
     * The nullable flag is independent of the column type. Asserted explicitly for the
     * framework casts since they always include null even when the column is non-nullable.
     */
    #[Test]
    public function it_keeps_framework_cast_nullability_idempotent(): void
    {
        $nonNullable = CastResolver::resolve($this->codebase, AsCollection::class, nullable: false);
        $nullable = CastResolver::resolve($this->codebase, AsCollection::class, nullable: true);

        $this->assertTrue($nonNullable->isNullable(), 'Framework Castable casts include null intrinsically');
        $this->assertTrue($nullable->isNullable(), 'Nullable column adds no additional null');
        $this->assertSame((string) $nonNullable, (string) $nullable, 'Repeated null union should be idempotent');
    }

    /**
     * Smoke test: signature should accept the `originalType` argument without exploding when
     * the cast class doesn't reach the CastsInboundAttributes branch.
     */
    #[Test]
    public function it_ignores_original_type_for_scalar_casts(): void
    {
        $originalType = Type::getString();
        $union = CastResolver::resolve($this->codebase, 'int', nullable: false, originalType: $originalType);

        $this->assertSame('int', (string) $union);
    }

    /**
     * Regression coverage for the issue #930 colon-stripping bug: `Money:USD` previously
     * fell through to mixed because the class-name path kept the colon and class_exists()
     * returned false. With the fix, the class is recognised; without a wired Codebase the
     * Castable/CastsAttributes branch returns null and we fall through to mixed cleanly.
     */
    #[Test]
    public function it_strips_parameters_before_class_existence_check(): void
    {
        $union = CastResolver::resolve($this->codebase, CastResolverBackedEnum::class . ':USD', nullable: false);

        // BackedEnum branch fires before any Castable/CastsAttributes check.
        $this->assertSame(CastResolverBackedEnum::class, (string) $union);
    }

    /**
     * A file that fails mid-load keeps what it declared before the failure (here a function), so a second
     * include dies with an uncatchable "Cannot redeclare function" fatal. Every check on one unscanned
     * target (enum, then each caster contract), and every later cast naming it, must load it at most once.
     * A separate process, because a regression is a fatal that would end the whole PHPUnit run.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function it_loads_an_unscanned_target_at_most_once(): void
    {
        foreach ([CastResolverPartialLoadEnum::class, 'enum:' . CastResolverPartialLoadEnum::class, CastResolverPartialLoadEnum::class] as $cast) {
            $this->assertSame('mixed', (string) CastResolver::resolve($this->codebase, $cast, nullable: false));
        }
    }

    /**
     * A string cast (`'Vendor\Status'`, not `Status::class`) does not make Psalm scan its target, so it
     * has no storage; CastResolver then asks the runtime, as before #1652.
     */
    #[Test]
    public function it_resolves_an_enum_psalm_never_scanned(): void
    {
        $union = CastResolver::resolve($this->codebase, CastResolverUnscannedEnum::class, nullable: false);

        $this->assertSame(CastResolverUnscannedEnum::class, (string) $union);
    }

    /** The runtime fallback must not let a target that fails to load escape (#1652). */
    #[Test]
    public function it_treats_an_unscanned_target_that_fails_to_load_as_unknown(): void
    {
        $union = CastResolver::resolve($this->codebase, 'enum:' . CastResolverThrowsOnLoadEnum::class, nullable: false);

        $this->assertSame('mixed', (string) $union);
    }

    /** Storage comes first: a scanned target is resolved without loading its file (#1652). */
    #[Test]
    public function it_resolves_a_scanned_enum_without_loading_it(): void
    {
        $this->codebase->classlike_storage_provider->create(CastResolverThrowsOnLoadEnum::class)->is_enum = true;

        $union = CastResolver::resolve($this->codebase, 'enum:' . CastResolverThrowsOnLoadEnum::class, nullable: false);

        $this->assertSame(CastResolverThrowsOnLoadEnum::class, (string) $union);
    }
}
