<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\ColumnFallback;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\PluginConfig;

#[CoversClass(PluginConfig::class)]
#[CoversClass(ColumnFallback::class)]
#[CoversClass(Plugin::class)]
final class PluginConfigTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        $this->originalEnv = $env !== false ? $env : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv !== null) {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=' . $this->originalEnv);
        } else {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        }
    }

    public function test_defaults_when_no_xml(): void
    {
        $config = PluginConfig::fromXml(null);

        $this->assertSame(ColumnFallback::Migrations, $config->columnFallback);
        $this->assertFalse($config->failOnInternalError);
    }

    public function test_column_fallback_none(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="none" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->columnFallback);
    }

    public function test_column_fallback_migrations(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="migrations" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::Migrations, $config->columnFallback);
    }

    public function test_invalid_column_fallback_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="invalid" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid columnFallback value 'invalid'");

        PluginConfig::fromXml($xml);
    }

    public function test_fail_on_internal_error_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->failOnInternalError);
    }

    public function test_fail_on_internal_error_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->failOnInternalError);
    }

    public function test_invalid_fail_on_internal_error_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid failOnInternalError value 'yes'");

        PluginConfig::fromXml($xml);
    }

    public function test_cache_path_uses_env_var(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    public function test_cache_path_trims_trailing_separator(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom/');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    public function test_cache_path_uses_temp_dir_by_default(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $config = PluginConfig::fromXml(null);

        $expectedPrefix = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-';
        $this->assertStringStartsWith($expectedPrefix, $config->cachePath);
    }

    public function test_cache_path_is_deterministic(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $first = PluginConfig::fromXml(null);
        $second = PluginConfig::fromXml(null);

        $this->assertSame($first->cachePath, $second->cachePath);
    }

    public function test_get_cache_location_creates_and_returns_dir(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache-loc');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getCacheLocation($config);

        $this->assertSame('/tmp/psalm-test-cache-loc', $location);
    }

    public function test_get_alias_stub_location_ends_with_filename(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getAliasStubLocation($config);

        $this->assertSame('/tmp/psalm-test-cache' . \DIRECTORY_SEPARATOR . 'aliases.stubphp', $location);
    }

    public function test_full_config(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test');

        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<modelProperties columnFallback="none" />'
            . '<failOnInternalError value="true" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->columnFallback);
        $this->assertTrue($config->failOnInternalError);
        $this->assertSame('/tmp/psalm-test', $config->cachePath);
    }
}
