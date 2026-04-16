<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function defaults_when_no_xml(): void
    {
        $config = PluginConfig::fromXml(null);

        $this->assertSame(ColumnFallback::Migrations, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->failOnInternalError);
        $this->assertFalse($config->findMissingTranslations);
        $this->assertFalse($config->findMissingViews);
        $this->assertFalse($config->findOctaneIncompatibleBindings);
        $this->assertTrue($config->resolveDynamicWhereClauses);
    }

    #[Test]
    public function column_fallback_none(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="none" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
    }

    #[Test]
    public function column_fallback_migrations(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="migrations" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::Migrations, $config->modelPropertiesColumnFallback);
    }

    #[Test]
    public function invalid_column_fallback_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="invalid" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid columnFallback value 'invalid'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function fail_on_internal_error_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->failOnInternalError);
    }

    #[Test]
    public function fail_on_internal_error_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->failOnInternalError);
    }

    #[Test]
    public function invalid_fail_on_internal_error_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid failOnInternalError value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_missing_translations_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findMissingTranslations);
    }

    #[Test]
    public function find_missing_translations_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findMissingTranslations);
    }

    #[Test]
    public function invalid_find_missing_translations_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findMissingTranslations value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_missing_views_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findMissingViews);
    }

    #[Test]
    public function find_missing_views_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findMissingViews);
    }

    #[Test]
    public function invalid_find_missing_views_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findMissingViews value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_octane_incompatible_bindings_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBindings value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findOctaneIncompatibleBindings);
    }

    #[Test]
    public function find_octane_incompatible_bindings_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBindings value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findOctaneIncompatibleBindings);
    }

    #[Test]
    public function invalid_find_octane_incompatible_bindings_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBindings value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findOctaneIncompatibleBindings value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function dynamic_where_methods_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->resolveDynamicWhereClauses);
    }

    #[Test]
    public function dynamic_where_methods_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->resolveDynamicWhereClauses);
    }

    #[Test]
    public function invalid_dynamic_where_methods_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid resolveDynamicWhereClauses value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function cache_path_uses_env_var(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    #[Test]
    public function cache_path_trims_trailing_separator(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom/');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    #[Test]
    public function cache_path_uses_temp_dir_by_default(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $config = PluginConfig::fromXml(null);

        $expectedPrefix = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-';
        $this->assertStringStartsWith($expectedPrefix, $config->cachePath);
    }

    #[Test]
    public function cache_path_is_deterministic(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $first = PluginConfig::fromXml(null);
        $second = PluginConfig::fromXml(null);

        $this->assertSame($first->cachePath, $second->cachePath);
    }

    #[Test]
    public function get_cache_location_creates_and_returns_dir(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache-loc');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getCacheLocation($config);

        $this->assertSame('/tmp/psalm-test-cache-loc', $location);
    }

    #[Test]
    public function get_alias_stub_location_ends_with_filename(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getAliasStubLocation($config);

        $this->assertSame('/tmp/psalm-test-cache' . \DIRECTORY_SEPARATOR . 'aliases.stubphp', $location);
    }

    #[Test]
    public function full_config(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test');

        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<modelProperties columnFallback="none" />'
            . '<resolveDynamicWhereClauses value="false" />'
            . '<failOnInternalError value="true" />'
            . '<findMissingTranslations value="true" />'
            . '<findMissingViews value="true" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->resolveDynamicWhereClauses);
        $this->assertTrue($config->findMissingTranslations);
        $this->assertTrue($config->findMissingViews);
        $this->assertSame('/tmp/psalm-test', $config->cachePath);
        $this->assertTrue($config->failOnInternalError);
    }
}
