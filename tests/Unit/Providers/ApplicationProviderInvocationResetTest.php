<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;

#[CoversClass(ApplicationProvider::class)]
final class ApplicationProviderInvocationResetTest extends TestCase
{
    #[Test]
    public function reset_forces_the_next_invocation_to_boot_a_fresh_application(): void
    {
        ApplicationProvider::reset();
        ApplicationProvider::bootApp();
        $first = ApplicationProvider::getApp();

        ApplicationProvider::reset();

        $this->assertNull(\Illuminate\Support\Facades\Facade::getFacadeApplication());

        ApplicationProvider::bootApp();

        $this->assertNotSame($first, ApplicationProvider::getApp());
    }
}
