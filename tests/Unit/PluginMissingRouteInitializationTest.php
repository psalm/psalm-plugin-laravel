<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Rules\MissingRouteHandler;
use Psalm\LaravelPlugin\Plugin;
use Psalm\Progress\VoidProgress;

/**
 * Fast, in-process guard for `Plugin::initMissingRouteHandler()`'s empty-table bail — the branch
 * a phpt type test cannot exercise as a POSITIVE assertion (the psalm-tester harness boots this
 * exact Testbench fallback, so every phpt run already goes through this path implicitly) and
 * that a real Psalm subprocess would be needlessly slow to pin directly. Boots the plugin's
 * Testbench fallback (no bootstrap/app.php at the plugin root, mirroring the psalm-tester
 * harness) — the router is bound but no route file is ever loaded, so the named-route table
 * comes back empty. `MissingRouteHandler::init()` must not be called in that case, or an app
 * with zero known routes would report every route name as missing.
 *
 * The positive path (a real, non-empty route table) is guarded end-to-end by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\MissingRouteEmissionTest}.
 */
#[CoversClass(Plugin::class)]
final class PluginMissingRouteInitializationTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ApplicationProvider::reset();
        MissingRouteHandler::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ApplicationProvider::reset();
        MissingRouteHandler::reset();
    }

    #[Test]
    public function stays_disabled_when_the_booted_app_has_no_named_routes(): void
    {
        ApplicationProvider::bootApp();

        $this->invokeInitMissingRouteHandler();

        $this->assertFalse($this->isEnabled(), 'MissingRouteHandler must stay disabled when the named-route table is empty.');
        $this->assertSame([], $this->registeredNames());
    }

    private function invokeInitMissingRouteHandler(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'initMissingRouteHandler');
        $method->invoke(new Plugin(), new VoidProgress());
    }

    private function isEnabled(): bool
    {
        $property = new \ReflectionProperty(MissingRouteHandler::class, 'enabled');

        /** @var bool $value */
        $value = $property->getValue();

        return $value;
    }

    /** @return array<string, true> */
    private function registeredNames(): array
    {
        $property = new \ReflectionProperty(MissingRouteHandler::class, 'names');

        /** @var array<string, true> $value */
        $value = $property->getValue();

        return $value;
    }
}
