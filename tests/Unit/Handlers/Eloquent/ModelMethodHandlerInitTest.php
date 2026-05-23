<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;

#[CoversClass(ModelMethodHandler::class)]
final class ModelMethodHandlerInitTest extends TestCase
{
    protected function tearDown(): void
    {
        ModelMethodHandler::init();
    }

    /**
     * Regression: $unresolvedCache stores the dynamic-where existence verdict, which
     * depends on the runtime-mutable DynamicWhereResolver::isEnabled() flag. If a
     * previous "enabled" bootstrap cached `true` for a Model::where{Column} call, a
     * subsequent "disabled" bootstrap in the same process must not inherit it — Plugin
     * re-bootstrap calls ModelMethodHandler::init() to clear the cache alongside
     * DynamicWhereResolver::reset().
     */
    #[Test]
    public function init_clears_unresolved_cache_so_dynamic_where_verdict_does_not_leak_across_bootstraps(): void
    {
        ModelMethodHandler::registerCustomBuilder(StubModelForInit::class, StubBuilderForInit::class);

        ModelMethodHandler::init();

        // After init() the custom-builder map must be empty; otherwise a re-bootstrap
        // would inherit registrations that the new run hasn't repopulated yet.
        $reflection = new \ReflectionClass(ModelMethodHandler::class);
        $prop = $reflection->getProperty('customBuilderMap');
        $prop->setAccessible(true);
        $this->assertSame([], $prop->getValue(), 'init() must clear the custom-builder map.');

        $unresolved = $reflection->getProperty('unresolvedCache');
        $unresolved->setAccessible(true);
        $this->assertSame([], $unresolved->getValue(), 'init() must clear the unresolved-method cache so a config flip is honoured.');
    }
}

/**
 * @internal
 */
final class StubModelForInit extends Model {}

/**
 * @internal
 * @extends Builder<StubModelForInit>
 */
final class StubBuilderForInit extends Builder {}
