<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use App\Providers\SubscriptionServiceProvider;
use App\Services\SubscriptionClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\BootTimeProviderHarvester;
use Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider;
use Psalm\Progress\VoidProgress;

/**
 * Smoke tests for the boot-time entry point. The autoloader fixture seeds the
 * map via the same `harvestProvider()` path the type-test bootstrap uses, so
 * the assertions here both verify the public API works in isolation AND act as
 * a regression gate: if `BootTimeProviderHarvester::harvestProvider()` stops
 * populating the map for the fixture, the type tests fail downstream too.
 */
#[CoversClass(BootTimeProviderHarvester::class)]
final class BootTimeProviderHarvesterTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        ContainerBindingMapProvider::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ContainerBindingMapProvider::reset();
        parent::tearDown();
    }

    #[Test]
    public function harvest_provider_populates_map_from_fixture_provider(): void
    {
        BootTimeProviderHarvester::harvestProvider(
            SubscriptionServiceProvider::class,
            new VoidProgress(),
        );

        self::assertSame(
            SubscriptionClient::class,
            ContainerBindingMapProvider::lookup('subscription'),
        );
    }

    #[Test]
    public function harvest_provider_swallows_missing_class(): void
    {
        BootTimeProviderHarvester::harvestProvider(
            // @phpstan-ignore-next-line — intentional non-existent FQCN
            'Nonexistent\\Provider\\DoesNotExist',
            new VoidProgress(),
        );

        // No crash; map remains empty.
        self::assertNull(ContainerBindingMapProvider::lookup('subscription'));
    }

    #[Test]
    public function harvest_all_runs_without_error_in_empty_environment(): void
    {
        // The plugin's own dev environment has no first-party `bootstrap/providers.php`
        // or `config/app.php`, so harvestAll() exercises the vendor-only path. The
        // call should succeed and not throw regardless of what's in installed.json.
        BootTimeProviderHarvester::harvestAll(new VoidProgress());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function harvest_all_with_extra_providers_seeds_map(): void
    {
        BootTimeProviderHarvester::harvestAll(
            new VoidProgress(),
            [SubscriptionServiceProvider::class],
        );

        self::assertSame(
            SubscriptionClient::class,
            ContainerBindingMapProvider::lookup('subscription'),
        );
    }
}
