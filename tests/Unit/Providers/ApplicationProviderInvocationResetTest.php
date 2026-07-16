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
        $oldAlias = 'PsalmLaravelPluginOldInvocationAlias' . \str_replace('.', '', \uniqid('', true));
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias($oldAlias, \stdClass::class);
        $loader->register();

        ApplicationProvider::reset();

        $this->assertNull(\Illuminate\Support\Facades\Facade::getFacadeApplication());
        $this->assertNotSame($first, \Illuminate\Container\Container::getInstance());
        $this->assertArrayNotHasKey($oldAlias, \Illuminate\Foundation\AliasLoader::getInstance()->getAliases());
        $this->assertFalse(\class_exists($oldAlias));

        ApplicationProvider::bootApp();

        $this->assertNotSame($first, ApplicationProvider::getApp());
    }
}
