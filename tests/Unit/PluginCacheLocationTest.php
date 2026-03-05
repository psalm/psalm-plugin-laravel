<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;

use function getenv;
use function md5;
use function putenv;
use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(Plugin::class)]
final class PluginCacheLocationTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $env = getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        $this->originalEnv = $env !== false ? $env : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv !== null) {
            putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=' . $this->originalEnv);
        } else {
            putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        }
    }

    public function test_cache_location_uses_env_var_when_set(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/custom/cache/path');

        $location = $this->invokeGetCacheLocation();

        self::assertSame('/custom/cache/path', $location);
    }

    public function test_cache_location_trims_trailing_separator(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/custom/cache/path/');

        $location = $this->invokeGetCacheLocation();

        self::assertSame('/custom/cache/path', $location);
    }

    public function test_cache_location_uses_temp_dir_with_project_hash_by_default(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $location = $this->invokeGetCacheLocation();

        $expectedPrefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'psalm-laravel-';
        self::assertStringStartsWith($expectedPrefix, $location);
    }

    public function test_cache_location_is_deterministic(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $first = $this->invokeGetCacheLocation();
        $second = $this->invokeGetCacheLocation();

        self::assertSame($first, $second);
    }

    public function test_alias_stub_location_ends_with_filename(): void
    {
        putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/test-cache');

        $location = $this->invokeGetAliasStubLocation();

        self::assertSame('/tmp/test-cache' . DIRECTORY_SEPARATOR . 'aliases.stubphp', $location);
    }

    private function invokeGetCacheLocation(): string
    {
        $method = new \ReflectionMethod(Plugin::class, 'getCacheLocation');

        /** @var string */
        return $method->invoke(null);
    }

    private function invokeGetAliasStubLocation(): string
    {
        $method = new \ReflectionMethod(Plugin::class, 'getAliasStubLocation');

        /** @var string */
        return $method->invoke(null);
    }
}
