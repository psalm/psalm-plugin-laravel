<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule;
use Psalm\LaravelPlugin\Handlers\Magic\ReturnTypeResolver;

/**
 * Unit tests for ReturnTypeResolver.
 *
 * The core resolve logic (self-return detection, type construction) depends on
 * Psalm's Codebase which is final and not feasible to mock. Those paths are
 * covered by the type tests in MagicForwardingHandlerTest.phpt.
 *
 * These tests verify the cache and null-guard behavior that can be tested
 * without a Codebase instance.
 */
#[CoversClass(ReturnTypeResolver::class)]
final class ReturnTypeResolverTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ReturnTypeResolver::initForRule([]);
    }

    #[Test]
    public function resolve_returns_null_when_template_params_are_null(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Source',
            searchClasses: ['App\\Target'],
        );

        // We can't easily construct a real Codebase, but null template params
        // short-circuit before any Codebase access. Use a stub via reflection.
        $result = ReturnTypeResolver::resolve($rule, 'App\\Source', null, $this->createCodebaseStub(), 'where');

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $result);
    }

    #[Test]
    public function resolve_returns_null_when_template_params_are_empty(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Source',
            searchClasses: ['App\\Target'],
        );

        $result = ReturnTypeResolver::resolve($rule, 'App\\Source', [], $this->createCodebaseStub(), 'where');

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $result);
    }

    #[Test]
    public function initForRule_clears_cache(): void
    {
        // Access the private cache via reflection to verify reset works
        $reflection = new \ReflectionClass(ReturnTypeResolver::class);
        $cacheProperty = $reflection->getProperty('selfReturnCache');

        // Seed the cache with a fake entry
        $cacheProperty->setValue(null, ['test::key' => true]);
        $this->assertNotEmpty($cacheProperty->getValue());

        ReturnTypeResolver::initForRule([]);

        $this->assertEmpty($cacheProperty->getValue());
    }

    /**
     * Create a minimal Codebase stub that won't be accessed
     * (tests short-circuit before Codebase interaction).
     *
     * Codebase is final, so we use an uninitialized instance via reflection.
     */
    private function createCodebaseStub(): \Psalm\Codebase
    {
        $reflection = new \ReflectionClass(\Psalm\Codebase::class);

        /** @var \Psalm\Codebase */
        return $reflection->newInstanceWithoutConstructor();
    }
}
