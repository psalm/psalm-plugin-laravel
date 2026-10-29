<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\ArrayObject as EloquentArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Stringable as IlluminateStringable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CastResolverBackedEnum;

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
    private Codebase $codebase;

    /** @var list<string> */
    private const DATE_STORAGE_CLASSES = ['DateCastCarbon', 'DateCastPlain'];

    #[\Override]
    protected function setUp(): void
    {
        // Partial Codebase: empty storage, so class lookups fall back to the runtime.
        $this->codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $this->codebase->classlike_storage_provider = new ClassLikeStorageProvider();
        $this->codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // `Date::use()` is a static of the framework, and ClassLikeStorageProvider keeps its storage in a static too.
        DateFactory::useDefault();
        foreach (self::DATE_STORAGE_CLASSES as $class) {
            $this->codebase->classlike_storage_provider->remove($class);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function scalarCastProvider(): iterable
    {
        yield 'integer alias' => ['integer', 'int'];
        yield 'double alias' => ['double', 'float'];
        yield 'decimal' => ['decimal', 'string'];
        yield 'boolean alias' => ['boolean', 'bool'];
        yield 'json alias' => ['json', 'array<array-key, mixed>'];
        yield 'collection' => ['collection', \Illuminate\Support\Collection::class];
        yield 'datetime' => ['datetime', \Illuminate\Support\Carbon::class];
        yield 'immutable_datetime' => ['immutable_datetime', \Carbon\CarbonImmutable::class];
    }

    #[Test]
    #[DataProvider('scalarCastProvider')]
    public function it_resolves_scalar_casts(string $cast, string $expected): void
    {
        $union = CastResolver::resolve($this->codebase, $cast, nullable: false);

        $this->assertSame($expected, (string) $union);
        $this->assertFalse($union->isNullable(), 'Non-nullable column must not produce a nullable type');
        $this->assertTrue(
            CastResolver::resolve($this->codebase, $cast, nullable: true)->isNullable(),
            "Nullable column must produce a nullable type for {$cast}",
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function encryptedCastProvider(): iterable
    {
        // Laravel's HasAttributes::isEncryptedCastable accepts only array/json/collection/object suffixes.
        yield 'encrypted:array' => ['encrypted:array', 'array<array-key, mixed>'];
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

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function frameworkCastProvider(): iterable
    {
        $arrayObject = EloquentArrayObject::class . '<array-key, mixed>|null';
        $collection = IlluminateCollection::class . '<array-key, mixed>|null';
        $stringable = IlluminateStringable::class . '|null';

        yield 'AsArrayObject' => [AsArrayObject::class, $arrayObject];
        yield 'AsCollection' => [AsCollection::class, $collection];
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

    /** @return iterable<string, array{0: string}> */
    public static function dateCastProvider(): iterable
    {
        yield 'date' => ['date'];
        yield 'datetime' => ['datetime'];
        yield 'custom_datetime' => ['custom_datetime'];
    }

    /**
     * `Date::use()` decides what a `date` cast hands back (#1999); {@see \Tests\Psalm\LaravelPlugin\Unit\Internal\ConfiguredDateClassTest}
     * owns the resolver matrix. Here only the cast level: nullability, `mixed` when the resolver has no class, and
     * `immutable_*` casts ignoring `Date::use()`.
     */
    #[Test]
    #[DataProvider('dateCastProvider')]
    public function it_types_date_casts_as_the_configured_date_class(string $cast): void
    {
        $storage = $this->codebase->classlike_storage_provider;
        $storage->create('DateCastCarbon')->class_implements = [\strtolower(CarbonInterface::class) => CarbonInterface::class];
        $storage->create('DateCastPlain');

        DateFactory::useClass('DateCastCarbon');
        $this->assertSame('DateCastCarbon|null', (string) CastResolver::resolve($this->codebase, $cast, nullable: true));
        $this->assertSame('DateCastCarbon', (string) CastResolver::resolve($this->codebase, $cast, nullable: false));

        // The resolver has no class: `mixed`, nullable or not.
        DateFactory::useClass('DateCastPlain');
        $this->assertSame('mixed', (string) CastResolver::resolve($this->codebase, $cast, nullable: true));
        $this->assertSame('mixed', (string) CastResolver::resolve($this->codebase, $cast, nullable: false));

        $this->assertSame(
            \Carbon\CarbonImmutable::class . '|null',
            (string) CastResolver::resolve($this->codebase, 'immutable_' . $cast, nullable: true),
        );
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unknown_cast(): void
    {
        $union = CastResolver::resolve($this->codebase, 'NonExistent\\CastClass', nullable: false);

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
}
