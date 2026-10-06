<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\ColumnFallback;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Config\PluginOverrides;
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
        $this->assertFalse($config->findUnconfiguredFilesystemDisks);
        $this->assertFalse($config->findUnregisteredRouteNames);
        $this->assertFalse($config->reportImplicitQueryBuilderCalls);
        $this->assertFalse($config->findSerializedQueuedModels);
        $this->assertFalse($config->experimental);
        // null = auto-detect via class_exists('Laravel\Octane\Octane') at runtime;
        // explicit true/false in XML overrides the auto-detection.
        $this->assertNull($config->findOctaneIncompatibleBinding);
        $this->assertNull($config->findPromptInjection);
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
    public function find_unconfigured_filesystem_disks_follows_experimental(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        $this->assertTrue(PluginConfig::fromXml($xml)->findUnconfiguredFilesystemDisks);
    }

    #[Test]
    public function find_unconfigured_filesystem_disks_explicit_false_wins_over_experimental(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental value="true" /><findUnconfiguredFilesystemDisks value="false" /></pluginClass>',
        );

        $this->assertFalse(PluginConfig::fromXml($xml)->findUnconfiguredFilesystemDisks);
    }

    #[Test]
    public function find_prompt_injection_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findPromptInjection value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findPromptInjection);
    }

    #[Test]
    public function find_prompt_injection_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findPromptInjection value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findPromptInjection);
    }

    #[Test]
    public function find_prompt_injection_without_value_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findPromptInjection /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('findPromptInjection> requires a `value` attribute');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_prompt_injection_invalid_value_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findPromptInjection value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findPromptInjection value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_unregistered_route_names_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findUnregisteredRouteNames value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findUnregisteredRouteNames);
    }

    #[Test]
    public function find_unregistered_route_names_defaults_to_experimental(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findUnregisteredRouteNames);
    }

    #[Test]
    public function find_unregistered_route_names_explicit_false_wins_over_experimental(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<experimental value="true" />'
            . '<findUnregisteredRouteNames value="false" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findUnregisteredRouteNames);
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
        $this->assertFalse($config->findSerializedQueuedModels);
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
    public function find_serialized_queued_models_defaults_to_experimental(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findSerializedQueuedModels);
    }

    #[Test]
    public function find_serialized_queued_models_explicit_false_wins_over_experimental(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<experimental value="true" />'
            . '<findSerializedQueuedModels value="false" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findSerializedQueuedModels);
    }

    #[Test]
    public function find_serialized_queued_models_explicit_true_without_experimental(): void
    {
        // The one combination `= $experimental` alone cannot satisfy: the flag must be read.
        $xml = new \SimpleXMLElement('<pluginClass><findSerializedQueuedModels value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findSerializedQueuedModels);
    }

    #[Test]
    public function find_serialized_queued_models_absent_without_experimental_stays_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass />');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findSerializedQueuedModels);
    }

    #[Test]
    public function find_serialized_queued_models_no_value_attribute_treated_as_absent(): void
    {
        // A present element without a `value` attribute is auto-detect, same as a
        // missing element — see xmlOptionalBoolAttr().
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<experimental value="true" />'
            . '<findSerializedQueuedModels />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findSerializedQueuedModels);
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
            . '<findMissingTranslations value="true" />'
            . '<findMissingViews value="true" />'
            . '<findUnconfiguredFilesystemDisks value="true" />'
            . '<experimental value="true" />'
            . '<failOnInternalError value="true" />'
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
        $this->assertTrue($config->findUnconfiguredFilesystemDisks);
        $this->assertTrue($config->experimental);
        $this->assertSame('/tmp/psalm-test', $config->cachePath);
        $this->assertTrue($config->failOnInternalError);
        $this->assertSame(['app/Config', 'packages/*/config'], $config->configDirectories);
    }

    #[Test]
    public function the_override_layers_win_in_the_order_cli_env_xml_default(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="false" /></pluginClass>');

        $this->assertTrue(PluginConfig::fromXml(null)->resolveDynamicWhereClauses);
        $this->assertFalse(PluginConfig::fromXml($xml)->resolveDynamicWhereClauses);
        $this->assertTrue(PluginConfig::fromXml($xml, $this->layers(env: ['resolveDynamicWhereClauses=true']))->resolveDynamicWhereClauses);
        $this->assertFalse(PluginConfig::fromXml(null, $this->layers(cli: ['resolveDynamicWhereClauses=false'], env: ['resolveDynamicWhereClauses=true']))->resolveDynamicWhereClauses);
        // A key only the lower layer sets still applies under a higher layer that sets other keys.
        $this->assertFalse(PluginConfig::fromXml(null, $this->layers(cli: ['blade=true'], env: ['resolveConfigReturnTypes=false']))->resolveConfigReturnTypes);
    }

    #[Test]
    public function every_setting_can_be_overridden(): void
    {
        $config = PluginConfig::fromXml(null, $this->layers(cli: [
            'modelProperties.columnFallback=none',
            'resolveDynamicWhereClauses=false',
            'resolveConfigReturnTypes=false',
            'reportImplicitQueryBuilderCalls=true',
            'configDirectory=app/Config',
            'findMissingTranslations=true',
            'findMissingViews=true',
            'findUnconfiguredFilesystemDisks=true',
            'findUnregisteredRouteNames=true',
            'findSerializedQueuedModels=true',
            'findOctaneIncompatibleBinding=true',
            'findPromptInjection=false',
            'blade=true',
            'blade.cacheDir=/tmp/blade shadows',
            'blade.validateViewData=true',
            'blade.reportUnusedViewData=true',
            'blade.reportMixedIssues=true',
            'experimental=true',
            'failOnInternalError=true',
        ]));

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->resolveDynamicWhereClauses);
        $this->assertFalse($config->resolveConfigReturnTypes);
        $this->assertTrue($config->reportImplicitQueryBuilderCalls);
        $this->assertSame(['app/Config'], $config->configDirectories);
        $this->assertTrue($config->findMissingTranslations);
        $this->assertTrue($config->findMissingViews);
        $this->assertTrue($config->findUnconfiguredFilesystemDisks);
        $this->assertTrue($config->findUnregisteredRouteNames);
        $this->assertTrue($config->findSerializedQueuedModels);
        $this->assertTrue($config->findOctaneIncompatibleBinding);
        $this->assertFalse($config->findPromptInjection);
        $this->assertTrue($config->bladeEnabled);
        $this->assertSame('/tmp/blade shadows', $config->bladeCacheDir);
        $this->assertTrue($config->bladeValidateViewData);
        $this->assertTrue($config->bladeReportUnusedViewData);
        $this->assertTrue($config->bladeReportMixedIssues);
        $this->assertTrue($config->experimental);
        $this->assertTrue($config->failOnInternalError);
    }

    #[Test]
    public function an_override_can_flip_an_explicit_xml_tri_state_in_either_direction(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass><findOctaneIncompatibleBinding value="false" /><findPromptInjection value="true" /></pluginClass>',
        );

        $config = PluginConfig::fromXml($xml, $this->layers(cli: ['findOctaneIncompatibleBinding=true', 'findPromptInjection=false']));

        $this->assertTrue($config->findOctaneIncompatibleBinding);
        $this->assertFalse($config->findPromptInjection);
    }

    #[Test]
    public function a_config_directory_list_is_replaced_by_the_highest_layer_that_sets_it(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><configDirectory name="a" /><configDirectory name="b" /></pluginClass>');

        $this->assertSame(['a', 'b'], PluginConfig::fromXml($xml, $this->layers(cli: ['blade=true']))->configDirectories);
        $this->assertSame(['c', 'd'], PluginConfig::fromXml($xml, $this->layers(env: ['configDirectory=c', 'configDirectory=d']))->configDirectories);
        $this->assertSame(['e'], PluginConfig::fromXml($xml, $this->layers(cli: ['configDirectory=e'], env: ['configDirectory=c', 'configDirectory=d']))->configDirectories);
    }

    #[Test]
    public function experimental_derived_defaults_resolve_after_the_layers(): void
    {
        $derived = static fn(PluginConfig $config): array => [
            $config->findUnconfiguredFilesystemDisks,
            $config->findSerializedQueuedModels,
            $config->findUnregisteredRouteNames,
        ];
        $experimentalXml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        // An experimental override reaches keys that no layer sets explicitly...
        $this->assertSame([true, true, true], $derived(PluginConfig::fromXml(null, $this->layers(cli: ['experimental=true']))));
        // ...and switches them back off over an experimental XML...
        $this->assertSame([false, false, false], $derived(PluginConfig::fromXml($experimentalXml, $this->layers(env: ['experimental=false']))));

        // ...but an explicit value in any layer still wins, in both directions.
        $explicitXml = new \SimpleXMLElement('<pluginClass><findSerializedQueuedModels value="false" /></pluginClass>');
        $this->assertSame([true, false, true], $derived(PluginConfig::fromXml($explicitXml, $this->layers(cli: ['experimental=true']))));
        $this->assertSame([false, false, true], $derived(PluginConfig::fromXml($experimentalXml, $this->layers(cli: ['findUnconfiguredFilesystemDisks=false'], env: ['findSerializedQueuedModels=false']))));
    }

    #[Test]
    public function blade_override_enables_without_the_xml_element_and_keeps_xml_sub_settings(): void
    {
        $this->assertTrue(PluginConfig::fromXml(null, $this->layers(cli: ['blade=true']))->bladeEnabled);

        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade value="false" validateViewData="true" /></pluginClass>'),
            $this->layers(env: ['blade=true']),
        );

        $this->assertTrue($config->bladeEnabled);
        $this->assertTrue($config->bladeValidateViewData);
    }

    #[Test]
    public function blade_override_disables_an_xml_enabled_blade_and_the_cli_layer_beats_env(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><blade /></pluginClass>');

        $this->assertFalse(PluginConfig::fromXml($xml, $this->layers(env: ['blade=false']))->bladeEnabled);
        $this->assertTrue(PluginConfig::fromXml($xml, $this->layers(cli: ['blade=true'], env: ['blade=false']))->bladeEnabled);
    }

    #[Test]
    public function blade_sub_settings_never_enable_blade(): void
    {
        $subSettings = [
            'blade.cacheDir=/tmp/blade',
            'blade.validateViewData=true',
            'blade.reportUnusedViewData=true',
            'blade.reportMixedIssues=true',
        ];

        $this->assertFalse(PluginConfig::fromXml(null, $this->layers(cli: $subSettings))->bladeEnabled);
        $this->assertFalse(PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade value="false" /></pluginClass>'),
            $this->layers(env: $subSettings),
        )->bladeEnabled);
    }

    #[Test]
    public function blade_cache_dir_override_is_taken_verbatim_like_the_xml_attribute(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><blade cacheDir="from-xml" /></pluginClass>');

        $this->assertSame('/tmp/blade shadows', PluginConfig::fromXml($xml, $this->layers(cli: ['blade.cacheDir=/tmp/blade shadows/']))->bladeCacheDir);
        $this->assertSame('from-xml', PluginConfig::fromXml($xml, $this->layers(cli: ['blade=true']))->bladeCacheDir);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedXml(): iterable
    {
        yield 'bad bool' => ['<resolveDynamicWhereClauses value="maybe" />'];
        yield 'bad blade value' => ['<blade value="yes" />'];
        yield 'bad blade sub-setting' => ['<blade reportMixedIssues="yes" />'];
        yield 'bad enum' => ['<modelProperties columnFallback="db" />'];
        yield 'prompt injection without value' => ['<findPromptInjection />'];
        yield 'nameless configDirectory' => ['<configDirectory />'];
    }

    #[Test]
    #[DataProvider('malformedXml')]
    public function xml_is_still_validated_when_an_override_wins(string $inner): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginConfig::fromXml(
            new \SimpleXMLElement("<pluginClass>{$inner}</pluginClass>"),
            $this->layers(cli: [
                'resolveDynamicWhereClauses=true',
                'blade=true',
                'blade.reportMixedIssues=true',
                'modelProperties.columnFallback=none',
                'findPromptInjection=true',
                'configDirectory=a',
            ]),
        );
    }

    /**
     * @param list<string> $cli
     * @param list<string> $env
     */
    private function layers(array $cli = [], array $env = []): PluginOverrides
    {
        return PluginOverrides::parse($env, 'env')->over(PluginOverrides::parse($cli, '--plugin-option'));
    }
}
