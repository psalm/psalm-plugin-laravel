<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider;

#[CoversClass(ContainerBindingMapProvider::class)]
final class ContainerBindingMapProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ContainerBindingMapProvider::reset();
    }

    protected function tearDown(): void
    {
        ContainerBindingMapProvider::reset();
        parent::tearDown();
    }

    #[Test]
    public function lookup_returns_null_for_unknown_accessor(): void
    {
        $this->assertNull(ContainerBindingMapProvider::lookup('unknown.alias'));
    }

    #[Test]
    public function record_then_lookup_round_trips(): void
    {
        ContainerBindingMapProvider::record('subscription', \stdClass::class);

        $this->assertSame(\stdClass::class, ContainerBindingMapProvider::lookup('subscription'));
    }

    #[Test]
    public function record_first_write_wins(): void
    {
        ContainerBindingMapProvider::record('alias', \stdClass::class);
        ContainerBindingMapProvider::record('alias', \ArrayObject::class);

        $this->assertSame(\stdClass::class, ContainerBindingMapProvider::lookup('alias'), 'subsequent writes must be ignored so scan ordering does not produce non-deterministic maps');
    }

    #[Test]
    public function empty_accessor_is_rejected(): void
    {
        ContainerBindingMapProvider::record('', \stdClass::class);

        $this->assertNull(ContainerBindingMapProvider::lookup(''));
    }
}
