<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Rules\UnregisteredRouteNameHandler;

/**
 * Guards the conditions under which {@see UnregisteredRouteNameHandler::init()} must NOT arm the
 * rule, since arming against an untrustworthy route table turns every `route()` call into a false
 * positive. The positive emission is guarded end-to-end by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\UnregisteredRouteNameEmissionTest}; the
 * positive control below only proves the fixture still arms the rule, so each decline test
 * cannot pass because the fixture stopped resolving routes.
 */
#[CoversClass(UnregisteredRouteNameHandler::class)]
final class UnregisteredRouteNameHandlerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ApplicationProvider::reset();
        UnregisteredRouteNameHandler::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ApplicationProvider::reset();
        UnregisteredRouteNameHandler::reset();
    }

    #[Test]
    public function arms_with_the_route_table_of_a_clean_project_boot(): void
    {
        $this->bootFixtureAndInit();

        $this->assertTrue($this->isEnabled());
        // Not an exact match: the framework registers named routes of its own (storage.local*).
        $this->assertArrayHasKey('dashboard', $this->names());
        $this->assertArrayHasKey('posts.show', $this->names());
    }

    /**
     * The Testbench table is not reliably empty (on Laravel 12 the skeleton's local disk registers
     * `storage.local*`), so only the boot-mode gate keeps a package analysis silent.
     */
    #[Test]
    public function stays_off_under_the_testbench_fallback_boot(): void
    {
        ApplicationProvider::bootApp();
        $this->assertSame('testbench_fallback', ApplicationProvider::getBootMode());

        UnregisteredRouteNameHandler::init(ApplicationProvider::getApp());

        $this->assertFalse($this->isEnabled());
    }

    #[Test]
    public function stays_off_after_a_swallowed_bootstrap_error(): void
    {
        $this->bootFixtureAndInit(static function (): void {
            // A real partial boot is not a usable fixture: the router is empty there, which would
            // decline for the emptiness reason. Record the error on an otherwise populated app.
            (new \ReflectionProperty(ApplicationProvider::class, 'bootstrapError'))->setValue(null, new \RuntimeException('config failed'));
        });

        $this->assertFalse($this->isEnabled());
    }

    #[Test]
    public function stays_off_when_the_route_table_has_no_names(): void
    {
        $this->bootFixtureAndInit(static function (): void {
            ApplicationProvider::getApp()->make('router')->setRoutes(new RouteCollection());
        });

        $this->assertFalse($this->isEnabled());
    }

    #[Test]
    public function stays_off_when_the_app_registers_a_missing_named_route_resolver(): void
    {
        $this->bootFixtureAndInit(static function (): void {
            ApplicationProvider::getApp()->make('url')->resolveMissingNamedRoutesUsing(static fn(string $name): string => "/legacy/{$name}");
        });

        $this->assertFalse($this->isEnabled());
    }

    #[Test]
    public function stays_off_when_the_url_service_cannot_be_probed_for_a_resolver(): void
    {
        $this->bootFixtureAndInit(static function (): void {
            ApplicationProvider::getApp()->instance('url', new \stdClass());
        });

        $this->assertFalse($this->isEnabled());
    }

    /**
     * Boot the fixture's real bootstrap/app.php (withRouting(), so 'dashboard' and 'posts.show'
     * are genuinely registered), apply $tweak to the booted app, then arm the handler.
     *
     * @param (\Closure():void)|null $tweak
     */
    private function bootFixtureAndInit(?\Closure $tweak = null): void
    {
        $originalCwd = \getcwd();
        \assert(\is_string($originalCwd));

        \chdir(__DIR__ . '/../Fixtures/UnregisteredRouteName');

        try {
            ApplicationProvider::bootApp();
            $this->assertInstanceOf(UrlGenerator::class, ApplicationProvider::getApp()->make('url'));

            if ($tweak instanceof \Closure) {
                $tweak();
            }

            UnregisteredRouteNameHandler::init(ApplicationProvider::getApp());
        } finally {
            \chdir($originalCwd);
        }
    }

    private function isEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = (new \ReflectionProperty(UnregisteredRouteNameHandler::class, 'enabled'))->getValue();

        return $enabled;
    }

    /** @return array<string, true> */
    private function names(): array
    {
        /** @var array<string, true> $names */
        $names = (new \ReflectionProperty(UnregisteredRouteNameHandler::class, 'names'))->getValue();

        return $names;
    }
}
