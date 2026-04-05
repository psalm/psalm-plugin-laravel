<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;

/**
 * Unit tests for the docblock-based type extraction helpers in RelationMethodParser.
 *
 * These methods are private, so we test them via reflection.
 * The public extractDocblockRelatedModelType() is tested via type tests in
 * EloquentRelationAccessorTest.phpt (requires full Psalm analysis).
 */
#[CoversClass(RelationMethodParser::class)]
final class RelationMethodParserTest extends TestCase
{
    // --- extractFirstGenericParam ---

    #[Test]
    #[DataProvider('genericParamProvider')]
    public function extract_first_generic_param(string $docblock, ?string $expected): void
    {
        $result = $this->callPrivate('extractFirstGenericParam', $docblock);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function genericParamProvider(): iterable
    {
        yield '@psalm-return with union' => [
            '/** @psalm-return MorphTo<User|Post, $this> */',
            'User|Post',
        ];

        yield '@return with union' => [
            '/** @return MorphTo<User|Post, $this> */',
            'User|Post',
        ];

        yield '@phpstan-return with union' => [
            '/** @phpstan-return MorphTo<User|Post, $this> */',
            'User|Post',
        ];

        yield '@psalm-return takes priority over @return' => [
            "/**\n * @return MorphTo<Wrong, \$this>\n * @psalm-return MorphTo<Correct, \$this>\n */",
            'Correct',
        ];

        yield '@psalm-return takes priority over @phpstan-return' => [
            "/**\n * @phpstan-return MorphTo<Wrong, \$this>\n * @psalm-return MorphTo<Correct, \$this>\n */",
            'Correct',
        ];

        yield '@phpstan-return takes priority over @return' => [
            "/**\n * @return MorphTo<Wrong, \$this>\n * @phpstan-return MorphTo<Correct, \$this>\n */",
            'Correct',
        ];

        yield 'single generic param' => [
            '/** @return MorphTo<User> */',
            'User',
        ];

        yield 'FQCN in generic param' => [
            '/** @return MorphTo<\App\Models\User|\App\Models\Post, $this> */',
            '\App\Models\User|\App\Models\Post',
        ];

        yield 'no generic params' => [
            '/** @return MorphTo */',
            null,
        ];

        yield 'no return tag' => [
            '/** Get the commentable model. */',
            null,
        ];

        yield 'multiline docblock' => [
            "/**\n * Get the commentable.\n *\n * @psalm-return MorphTo<User|Post, \$this>\n */",
            'User|Post',
        ];
    }

    // --- resolveTypeNames ---

    #[Test]
    public function resolve_type_names_with_use_map(): void
    {
        $useMap = [
            'User' => 'App\\Models\\User',
            'Post' => 'App\\Models\\Post',
        ];

        /** @var \Psalm\Type\Union $result */
        $result = $this->callPrivate('resolveTypeNames', 'User|Post', $useMap, 'App\\Models');

        $atomics = $result->getAtomicTypes();
        $this->assertCount(2, $atomics);
        $this->assertArrayHasKey('App\\Models\\User', $atomics);
        $this->assertArrayHasKey('App\\Models\\Post', $atomics);
    }

    #[Test]
    public function resolve_type_names_with_fqcn(): void
    {
        /** @var \Psalm\Type\Union $result */
        $result = $this->callPrivate('resolveTypeNames', '\\App\\Models\\User|\\App\\Models\\Post', [], 'Other\\Namespace');

        $atomics = $result->getAtomicTypes();
        $this->assertCount(2, $atomics);
        $this->assertArrayHasKey('App\\Models\\User', $atomics);
        $this->assertArrayHasKey('App\\Models\\Post', $atomics);
    }

    #[Test]
    public function resolve_type_names_falls_back_to_namespace(): void
    {
        /** @var \Psalm\Type\Union $result */
        $result = $this->callPrivate('resolveTypeNames', 'User', [], 'App\\Models');

        $atomics = $result->getAtomicTypes();
        $this->assertCount(1, $atomics);
        $this->assertArrayHasKey('App\\Models\\User', $atomics);
    }

    #[Test]
    public function resolve_type_names_skips_non_class_types(): void
    {
        $result = $this->callPrivate('resolveTypeNames', '$this|static|null', [], 'App\\Models');

        $this->assertNull($result);
    }

    #[Test]
    public function resolve_type_names_mixed_class_and_non_class(): void
    {
        $useMap = ['User' => 'App\\Models\\User'];

        /** @var \Psalm\Type\Union $result */
        $result = $this->callPrivate('resolveTypeNames', 'User|null', $useMap, 'App\\Models');

        $atomics = $result->getAtomicTypes();
        $this->assertCount(1, $atomics);
        $this->assertArrayHasKey('App\\Models\\User', $atomics);
    }

    // --- extractNamespace ---

    #[Test]
    public function extract_namespace_from_fqcn(): void
    {
        $this->assertSame('App\\Models', $this->callPrivate('extractNamespace', 'App\\Models\\User'));
    }

    #[Test]
    public function extract_namespace_no_namespace(): void
    {
        $this->assertSame('', $this->callPrivate('extractNamespace', 'User'));
    }

    /**
     * Call a private static method on RelationMethodParser via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(RelationMethodParser::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(null, ...$args);
    }
}
