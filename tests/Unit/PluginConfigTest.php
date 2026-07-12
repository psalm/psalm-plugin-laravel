<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\ColumnFallback;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Plugin;

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
        $this->assertFalse($config->reportImplicitQueryBuilderCalls);
        $this->assertFalse($config->experimental);
        // null = auto-detect via class_exists('Laravel\Octane\Octane') at runtime;
        // explicit true/false in XML overrides the auto-detection.
        $this->assertNull($config->findOctaneIncompatibleBinding);
        $this->assertTrue($config->resolveDynamicWhereClauses);
        $this->assertTrue($config->resolveConfigReturnTypes);
        $this->assertSame([], $config->configDirectories);
    }

    #[Test]
    public function config_directories_single_entry(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><configDirectory name="app/Config" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(['app/Config'], $config->configDirectories);
    }

    #[Test]
    public function config_directories_multiple_entries_preserve_order(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="packages/*/config" />'
            . '<configDirectory name="vendor/foo/bar/config" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(
            ['app/Config', 'packages/*/config', 'vendor/foo/bar/config'],
            $config->configDirectories,
        );
    }

    #[Test]
    public function config_directories_throw_on_empty_name_attribute(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="" />'
            . '</pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<configDirectory> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function config_directories_throw_on_missing_name_attribute(): void
    {
        // Catches typos like <configDirectory path="..." /> where the user used the wrong
        // attribute name — without this guard the element is silently dropped and the
        // typo-warning behaviour kicks in only when *every* entry is malformed.
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory path="packages/forms/config" />'
            . '</pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<configDirectory> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
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
    public function report_implicit_query_builder_calls_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->reportImplicitQueryBuilderCalls);
    }

    #[Test]
    public function report_implicit_query_builder_calls_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->reportImplicitQueryBuilderCalls);
    }

    #[Test]
    public function experimental_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->experimental);
    }

    #[Test]
    public function experimental_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->experimental);
    }

    #[Test]
    public function invalid_experimental_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="maybe" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid experimental value 'maybe'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function invalid_report_implicit_query_builder_calls_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid reportImplicitQueryBuilderCalls value 'yes'");

        PluginConfig::fromXml($xml);
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
    public function find_octane_incompatible_binding_absent_yields_null(): void
    {
        // null is the auto-detect sentinel — Plugin::registerHandlers() falls
        // back to class_exists('Laravel\Octane\Octane') when this is null.
        $xml = new \SimpleXMLElement('<pluginClass />');

        $config = PluginConfig::fromXml($xml);

        $this->assertNull($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function find_octane_incompatible_binding_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function find_octane_incompatible_binding_false(): void
    {
        // Explicit false overrides auto-detect even when laravel/octane is installed.
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function invalid_find_octane_incompatible_binding_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findOctaneIncompatibleBinding value 'yes'");

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
    public function resolve_config_return_types_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->resolveConfigReturnTypes);
    }

    #[Test]
    public function resolve_config_return_types_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->resolveConfigReturnTypes);
    }

    #[Test]
    public function invalid_resolve_config_return_types_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid resolveConfigReturnTypes value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function cache_path_uses_env_var(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    #[Test]
    #[IgnoreDeprecations]
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
    #[IgnoreDeprecations]
    public function get_cache_location_creates_and_returns_dir(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache-loc');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getCacheLocation($config);

        $this->assertSame('/tmp/psalm-test-cache-loc', $location);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function get_alias_stub_location_ends_with_filename(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getAliasStubLocation($config);

        $this->assertSame('/tmp/psalm-test-cache' . \DIRECTORY_SEPARATOR . 'aliases.phpstub', $location);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function full_config(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test');

        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<modelProperties columnFallback="none" />'
            . '<resolveDynamicWhereClauses value="false" />'
            . '<resolveConfigReturnTypes value="false" />'
            . '<failOnInternalError value="true" />'
            . '<findMissingTranslations value="true" />'
            . '<findMissingViews value="true" />'
            . '<experimental value="true" />'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="packages/*/config" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->resolveDynamicWhereClauses);
        $this->assertFalse($config->resolveConfigReturnTypes);
        $this->assertTrue($config->findMissingTranslations);
        $this->assertTrue($config->findMissingViews);
        $this->assertTrue($config->experimental);
        $this->assertSame('/tmp/psalm-test', $config->cachePath);
        $this->assertTrue($config->failOnInternalError);
        $this->assertSame(['app/Config', 'packages/*/config'], $config->configDirectories);
    }
}
