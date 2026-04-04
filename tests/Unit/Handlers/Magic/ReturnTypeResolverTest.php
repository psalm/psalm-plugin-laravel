<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\Methods;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingStyle;
use Psalm\LaravelPlugin\Handlers\Magic\ReturnTypeResolver;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Tests for ReturnTypeResolver — the core return type computation for forwarded calls.
 *
 * Uses reflection to create Codebase instances without the full constructor,
 * following the same pattern as CustomCollectionDetectionTest and others.
 */
#[CoversClass(ReturnTypeResolver::class)]
final class ReturnTypeResolverTest extends TestCase
{
    private ClassLikeStorageProvider $storageProvider;

    /** @var list<string> Classes to clean up after each test */
    private array $registeredClasses = [];

    protected function setUp(): void
    {
        ReturnTypeResolver::resetCache();
        $this->storageProvider = new ClassLikeStorageProvider();
    }

    protected function tearDown(): void
    {
        foreach ($this->registeredClasses as $class) {
            $this->storageProvider->remove($class);
        }
    }

    // === Decorated style ===

    #[Test]
    public function decorated_returns_source_type_when_target_returns_self(): void
    {
        $this->registerMethod('Test\\Builder', 'where', new Union([new TNamedObject('Test\\Builder')]));

        $rule = $this->decoratedRule(['Test\\Builder']);
        $result = $this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'where');

        $this->assertInstanceOf(\Psalm\Type\Union::class, $result);
        $generic = \array_values($result->getAtomicTypes())[0];
        $this->assertInstanceOf(TGenericObject::class, $generic);
        $this->assertSame('Test\\HasOne', $generic->value);
        $this->assertCount(1, $generic->type_params);
    }

    #[Test]
    public function decorated_detects_is_static_return_type(): void
    {
        $staticReturn = (new TNamedObject('Test\\Builder'))->setIsStatic(true);
        $this->registerMethod('Test\\Builder', 'where', new Union([$staticReturn]));

        // No string indicators — is_static should be enough
        $rule = new ForwardingRule(
            sourceClass: 'Test\\Relation',
            searchClasses: ['Test\\Builder'],
            style: ForwardingStyle::Decorated,
            selfReturnIndicators: [],
        );

        $result = $this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'where');

        $this->assertInstanceOf(\Psalm\Type\Union::class, $result, 'is_static=true should be detected as self-returning');
    }

    #[Test]
    public function decorated_returns_null_for_non_self_returning_method(): void
    {
        $this->registerMethod('Test\\Builder', 'first', new Union([new TNamedObject('Test\\Model'), new TNull()]));

        $rule = $this->decoratedRule(['Test\\Builder']);
        $result = $this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'first');

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $result);
    }

    #[Test]
    public function decorated_returns_null_when_template_params_empty(): void
    {
        $this->registerMethod('Test\\Builder', 'where', new Union([new TNamedObject('Test\\Builder')]));

        $rule = $this->decoratedRule(['Test\\Builder']);

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\HasOne', [], 'where'));
        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\HasOne', null, 'where'));
    }

    // === AlwaysSelf style ===

    #[Test]
    public function always_self_returns_source_type_regardless_of_target(): void
    {
        $this->registerMethod('Test\\QueryBuilder', 'lockforupdate', new Union([new TNamedObject('Test\\QueryBuilder')]));

        $rule = new ForwardingRule(
            sourceClass: 'Test\\Builder',
            searchClasses: ['Test\\QueryBuilder'],
            style: ForwardingStyle::AlwaysSelf,
        );

        $templateParams = [new Union([new TNamedObject('Test\\User')])];
        $result = $this->resolve($rule, 'Test\\Builder', $templateParams, 'lockforupdate');

        $this->assertInstanceOf(\Psalm\Type\Union::class, $result);
        $generic = \array_values($result->getAtomicTypes())[0];
        $this->assertInstanceOf(TGenericObject::class, $generic);
        $this->assertSame('Test\\Builder', $generic->value);
    }

    #[Test]
    public function always_self_returns_null_when_template_params_empty(): void
    {
        $this->registerMethod('Test\\QueryBuilder', 'lock', new Union([new TNamedObject('Test\\QueryBuilder')]));

        $rule = new ForwardingRule(
            sourceClass: 'Test\\Builder',
            searchClasses: ['Test\\QueryBuilder'],
            style: ForwardingStyle::AlwaysSelf,
        );

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\Builder', [], 'lock'));
        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\Builder', null, 'lock'));
    }

    // === Passthrough style ===

    #[Test]
    public function passthrough_returns_target_return_type_as_is(): void
    {
        $targetReturn = new Union([
            new TGenericObject('Test\\Builder', [new Union([new TNamedObject('Test\\User')])]),
        ]);
        $this->registerMethod('Test\\Builder', 'where', $targetReturn);

        $rule = new ForwardingRule(
            sourceClass: 'Test\\Model',
            searchClasses: ['Test\\Builder'],
            style: ForwardingStyle::Passthrough,
        );

        $result = $this->resolve($rule, 'Test\\Model', null, 'where');

        $this->assertInstanceOf(\Psalm\Type\Union::class, $result);
        $this->assertSame($targetReturn->getId(), $result->getId());
    }

    // === Unknown methods ===

    #[Test]
    public function returns_null_for_unknown_method(): void
    {
        $this->registerMethod('Test\\Builder', 'where', new Union([new TNamedObject('Test\\Builder')]));

        $rule = $this->decoratedRule(['Test\\Builder']);

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'nonexistent'));
    }

    // === Cache behavior ===

    #[Test]
    public function reset_cache_clears_cached_results(): void
    {
        $this->registerMethod('Test\\Builder', 'where', new Union([new TNamedObject('Test\\Builder')]));

        $rule = $this->decoratedRule(['Test\\Builder']);

        $this->assertInstanceOf(\Psalm\Type\Union::class, $this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'where'));

        ReturnTypeResolver::resetCache();

        // Still works after reset (repopulates cache)
        $this->assertNotNull($this->resolve($rule, 'Test\\HasOne', $this->phoneTemplateParams(), 'where'));
    }

    // === Helpers ===

    /**
     * @return list<Union>
     */
    private function phoneTemplateParams(): array
    {
        return [new Union([new TNamedObject('Test\\Phone')])];
    }

    /**
     * @param list<string> $searchClasses
     */
    private function decoratedRule(array $searchClasses): ForwardingRule
    {
        return new ForwardingRule(
            sourceClass: 'Test\\Relation',
            searchClasses: $searchClasses,
            style: ForwardingStyle::Decorated,
            selfReturnIndicators: $searchClasses,
        );
    }

    private function registerMethod(string $className, string $methodName, ?Union $returnType): void
    {
        /** @var lowercase-string $methodNameLower */
        $methodNameLower = \strtolower($methodName);

        try {
            $classStorage = $this->storageProvider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            $classStorage = $this->storageProvider->create($className);
            $this->registeredClasses[] = $className;
        }

        $methodStorage = new MethodStorage();
        $methodStorage->return_type = $returnType;

        $declaringId = new MethodIdentifier($className, $methodNameLower);
        $classStorage->declaring_method_ids[$methodNameLower] = $declaringId;
        $classStorage->methods[$methodNameLower] = $methodStorage;
    }

    /**
     * @param list<Union>|null $templateParams
     */
    private function resolve(
        ForwardingRule $rule,
        string $sourceClass,
        ?array $templateParams,
        string $methodName,
    ): ?Union {
        $codebase = $this->createCodebase();
        return ReturnTypeResolver::resolve($rule, $sourceClass, $templateParams, $codebase, \strtolower($methodName));
    }

    /**
     * Create a minimal Codebase via reflection (same pattern as CustomCollectionDetectionTest).
     */
    private function createCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $progressRef = new \ReflectionProperty(Codebase::class, 'progress');
        $progressRef->setValue($codebase, new VoidProgress());

        // Set classlike_storage_provider
        $codebase->classlike_storage_provider = $this->storageProvider;

        // Create Methods via reflection (final class, complex constructor).
        // Methods::getStorage() delegates to classlike_storage_provider internally.
        $methods = (new \ReflectionClass(Methods::class))->newInstanceWithoutConstructor();

        $storageRef = new \ReflectionProperty(Methods::class, 'classlike_storage_provider');
        $storageRef->setValue($methods, $this->storageProvider);

        $codebase->methods = $methods;

        return $codebase;
    }
}
