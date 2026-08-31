<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util\Ast;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\Ast\ClassMethodResolver;

#[CoversClass(ClassMethodResolver::class)]
final class ClassMethodResolverTest extends TestCase
{
    #[Test]
    public function it_finds_a_method_declared_by_a_namespaced_trait(): void
    {
        $method = new Stmt\ClassMethod(new Identifier('related'));
        $trait = new Stmt\Trait_(new Identifier('HasRelations'), ['stmts' => [$method]]);
        $namespace = new Stmt\Namespace_(new Name('App\\Models\\Concerns'), [$trait]);

        $resolver = new \ReflectionMethod(ClassMethodResolver::class, 'findMethod');

        $result = $resolver->invoke(
            null,
            [$namespace],
            '',
            'App\\Models\\Concerns\\HasRelations',
            'related',
        );

        $this->assertSame($method, $result);
    }
}
