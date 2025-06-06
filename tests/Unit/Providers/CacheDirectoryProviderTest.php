<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\CacheDirectoryProvider;

use function putenv;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

final class CacheDirectoryProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
    }

    public function testReturnsDefaultPathIfEnvNotSet(): void
    {
        $path = CacheDirectoryProvider::getCacheLocation();

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . 'cache', $path);
        $this->assertFileExists($path);
    }

    public function testReturnsCustomPathFromEnv(): void
    {
        $custom = sys_get_temp_dir() . '/psalm-laravel-stubs-test';
        putenv("PSALM_LARAVEL_PLUGIN_CACHE_PATH={$custom}");

        $this->assertSame($custom, CacheDirectoryProvider::getCacheLocation());
    }
}
