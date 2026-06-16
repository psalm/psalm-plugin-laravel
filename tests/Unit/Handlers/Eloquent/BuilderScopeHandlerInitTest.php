<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;

#[CoversClass(BuilderScopeHandler::class)]
final class BuilderScopeHandlerInitTest extends TestCase
{
    protected function tearDown(): void
    {
        BuilderScopeHandler::init();
    }

    /**
     * Regression: Plugin::registerHandlers calls BuilderScopeHandler::init() on every
     * (re-)bootstrap so no per-process state leaks across analysis runs. $pendingScopeModel
     * is the most sensitive entry — a short-lived producer->consumer hand-off whose stale
     * survival would shadow a later scope call — but every cache must clear too so a config
     * flip or a test fixture re-boot starts clean.
     */
    #[Test]
    public function init_clears_all_mutable_statics(): void
    {
        $reflection = new \ReflectionClass(BuilderScopeHandler::class);

        // Discover every mutable static array property by reflection rather than hardcoding the
        // list, so a future cache that init() forgets to clear fails this test instead of silently
        // leaking across a re-bootstrap.
        $arrayStatics = \array_filter(
            $reflection->getProperties(\ReflectionProperty::IS_STATIC),
            static fn(\ReflectionProperty $property): bool
                => $property->getType() instanceof \ReflectionNamedType
                    && $property->getType()->getName() === 'array',
        );

        $this->assertNotEmpty($arrayStatics, 'Expected BuilderScopeHandler to declare mutable static array state.');

        foreach ($arrayStatics as $property) {
            $property->setValue(null, ['__seed__' => '__seed__']);
        }

        BuilderScopeHandler::init();

        foreach ($arrayStatics as $property) {
            $this->assertSame(
                [],
                $property->getValue(),
                "init() must clear \${$property->getName()} so a re-bootstrap does not inherit it.",
            );
        }
    }
}
