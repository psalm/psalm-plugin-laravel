<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;

/**
 * Unit tests for the private AST helpers in RelationMethodParser, called via reflection.
 * The relation parsing as a whole is covered by the type tests (tests/Type/tests/Relation).
 */
#[CoversClass(RelationMethodParser::class)]
final class RelationMethodParserTest extends TestCase
{
    /**
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/879
     */
    #[Test]
    #[DataProvider('classConstFetchProvider')]
    public function resolve_class_const_fetch(string $classKeyword, string $declaringClass, ?string $parentClass, ?string $expected): void
    {
        $expr = new \PhpParser\Node\Expr\ClassConstFetch(
            new \PhpParser\Node\Name($classKeyword),
            new \PhpParser\Node\Identifier('class'),
        );

        $result = $this->callPrivate('resolveClassConstFetch', $expr, $declaringClass, $parentClass);

        $this->assertSame($expected, $result);
    }

    /** @return iterable<string, array{string, string, ?string, ?string}> */
    public static function classConstFetchProvider(): iterable
    {
        yield 'self resolves to declaring class' => [
            'self', 'App\\Models\\WorkOrderNote', 'Illuminate\\Database\\Eloquent\\Model', 'App\\Models\\WorkOrderNote',
        ];
        yield 'self is case-insensitive' => [
            'SELF', 'App\\Models\\WorkOrderNote', null, 'App\\Models\\WorkOrderNote',
        ];
        yield 'static resolves to declaring class (conservative — see resolveClassConstFetch docblock)' => [
            'static', 'App\\Models\\Tool', 'Illuminate\\Database\\Eloquent\\Model', 'App\\Models\\Tool',
        ];
        yield 'parent resolves to parent class FQCN' => [
            'parent', 'App\\Models\\PowerTool', 'App\\Models\\Tool', 'App\\Models\\Tool',
        ];
        yield 'parent returns null when no parent class is known' => [
            'parent', 'App\\Models\\Tool', null, null,
        ];
        yield 'regular FQCN falls back to toString (no resolvedName attribute)' => [
            'App\\Models\\Vehicle', 'App\\Models\\Customer', null, 'App\\Models\\Vehicle',
        ];
    }

    /**
     * Call a private static method on RelationMethodParser via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(RelationMethodParser::class, $method);

        return $ref->invoke(null, ...$args);
    }
}
