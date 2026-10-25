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

#[CoversClass(PluginConfig::class)]
#[CoversClass(ColumnFallback::class)]
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
    public function config_directories_preserve_order(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="packages/*/config" />'
            . '</pluginClass>',
        );

        $this->assertSame(['app/Config', 'packages/*/config'], PluginConfig::fromXml($xml)->configDirectories);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedConfigDirectories(): iterable
    {
        yield 'empty name' => ['<configDirectory name="" />'];
        // A typo like path="..." would otherwise be silently dropped.
        yield 'missing name' => ['<configDirectory path="packages/forms/config" />'];
    }

    #[Test]
    #[DataProvider('malformedConfigDirectories')]
    public function config_directories_throw_on_a_malformed_entry(string $entry): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><configDirectory name="app/Config" />' . $entry . '</pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<configDirectory> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function column_fallback_is_parsed_and_validated(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="none" /></pluginClass>');
        $this->assertSame(ColumnFallback::None, PluginConfig::fromXml($xml)->modelPropertiesColumnFallback);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid columnFallback value 'invalid'");

        PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="invalid" /></pluginClass>'));
    }

    /** @return iterable<string, array{string}> */
    public static function booleanElements(): iterable
    {
        foreach ([
            'failOnInternalError',
            'findMissingTranslations',
            'findMissingViews',
            'findPromptInjection',
            'findOctaneIncompatibleBinding',
            'reportImplicitQueryBuilderCalls',
            'resolveDynamicWhereClauses',
            'resolveConfigReturnTypes',
            'experimental',
        ] as $element) {
            yield $element => [$element];
        }
    }

    #[Test]
    #[DataProvider('booleanElements')]
    public function a_boolean_element_reads_true_and_false_and_rejects_anything_else(string $element): void
    {
        foreach ([true, false] as $expected) {
            $xml = new \SimpleXMLElement("<pluginClass><{$element} value=\"" . ($expected ? 'true' : 'false') . '" /></pluginClass>');

            $this->assertSame($expected, PluginConfig::fromXml($xml)->{$element});
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid {$element} value 'yes'");

        PluginConfig::fromXml(new \SimpleXMLElement("<pluginClass><{$element} value=\"yes\" /></pluginClass>"));
    }

    #[Test]
    public function find_prompt_injection_without_value_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('findPromptInjection> requires a `value` attribute');

        PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><findPromptInjection /></pluginClass>'));
    }

    /** @return iterable<string, array{string}> */
    public static function experimentalDerivedRules(): iterable
    {
        yield 'findUnconfiguredFilesystemDisks' => ['findUnconfiguredFilesystemDisks'];
        yield 'findUnregisteredRouteNames' => ['findUnregisteredRouteNames'];
        yield 'findSerializedQueuedModels' => ['findSerializedQueuedModels'];
    }

    #[Test]
    #[DataProvider('experimentalDerivedRules')]
    public function an_experimental_rule_follows_experimental_unless_set_explicitly(string $rule): void
    {
        $parse = static fn(string $inner): PluginConfig => PluginConfig::fromXml(new \SimpleXMLElement("<pluginClass>{$inner}</pluginClass>"));

        $this->assertFalse($parse('')->{$rule});
        $this->assertTrue($parse('<experimental value="true" />')->{$rule});
        $this->assertFalse($parse('<experimental value="true" />' . "<{$rule} value=\"false\" />")->{$rule});
        // The one combination `= $experimental` alone cannot satisfy: the flag must be read.
        $this->assertTrue($parse("<{$rule} value=\"true\" />")->{$rule});
        // A present element without a `value` attribute is auto-detect, same as a missing element.
        $this->assertTrue($parse('<experimental value="true" />' . "<{$rule} />")->{$rule});
    }

    #[Test]
    #[IgnoreDeprecations]
    public function cache_path_comes_from_the_env_var_trimmed_or_else_the_temp_dir(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom/');
        $this->assertSame('/tmp/psalm-test-custom', PluginConfig::fromXml(null)->cachePath);

        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        $this->assertStringStartsWith(
            \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-',
            PluginConfig::fromXml(null)->cachePath,
        );
    }

    #[Test]
    public function an_override_wins_over_xml_and_default(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="false" /></pluginClass>');

        $this->assertTrue(PluginConfig::fromXml(null)->resolveDynamicWhereClauses);
        $this->assertFalse(PluginConfig::fromXml($xml)->resolveDynamicWhereClauses);
        $this->assertTrue(PluginConfig::fromXml($xml, PluginOverrides::parse('resolveDynamicWhereClauses=true'))->resolveDynamicWhereClauses);
        $this->assertFalse(PluginConfig::fromXml(null, PluginOverrides::parse('resolveDynamicWhereClauses=false'))->resolveDynamicWhereClauses);
        // A key the overrides do not set keeps its XML value.
        $this->assertFalse(PluginConfig::fromXml($xml, PluginOverrides::parse('blade=true'))->resolveDynamicWhereClauses);
    }

    #[Test]
    public function every_key_reaches_its_setting(): void
    {
        $config = PluginConfig::fromXml(null, PluginOverrides::parse(\implode(' ', [
            'modelProperties.columnFallback=none',
            'resolveDynamicWhereClauses=false',
            'resolveConfigReturnTypes=false',
            'reportImplicitQueryBuilderCalls=true',
            'findMissingTranslations=true',
            'findMissingViews=true',
            'findUnconfiguredFilesystemDisks=true',
            'findUnregisteredRouteNames=true',
            'findSerializedQueuedModels=true',
            'findOctaneIncompatibleBinding=true',
            'findPromptInjection=false',
            'blade=true',
            'experimental=true',
            'failOnInternalError=true',
        ])));

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->resolveDynamicWhereClauses);
        $this->assertFalse($config->resolveConfigReturnTypes);
        $this->assertTrue($config->reportImplicitQueryBuilderCalls);
        $this->assertTrue($config->findMissingTranslations);
        $this->assertTrue($config->findMissingViews);
        $this->assertTrue($config->findUnconfiguredFilesystemDisks);
        $this->assertTrue($config->findUnregisteredRouteNames);
        $this->assertTrue($config->findSerializedQueuedModels);
        $this->assertTrue($config->findOctaneIncompatibleBinding);
        $this->assertFalse($config->findPromptInjection);
        $this->assertTrue($config->bladeEnabled);
        $this->assertTrue($config->experimental);
        $this->assertTrue($config->failOnInternalError);
    }

    #[Test]
    public function an_override_flips_explicit_xml_in_either_direction(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass><modelProperties columnFallback="none" /><findOctaneIncompatibleBinding value="false" /><findPromptInjection value="true" /></pluginClass>',
        );

        $config = PluginConfig::fromXml($xml, PluginOverrides::parse('modelProperties.columnFallback=migrations findOctaneIncompatibleBinding=true findPromptInjection=false'));

        $this->assertSame(ColumnFallback::Migrations, $config->modelPropertiesColumnFallback);
        $this->assertTrue($config->findOctaneIncompatibleBinding);
        $this->assertFalse($config->findPromptInjection);
    }

    #[Test]
    public function experimental_derived_defaults_resolve_after_the_overrides(): void
    {
        $derived = static fn(PluginConfig $config): array => [
            $config->findUnconfiguredFilesystemDisks,
            $config->findSerializedQueuedModels,
            $config->findUnregisteredRouteNames,
        ];
        $experimentalXml = new \SimpleXMLElement('<pluginClass><experimental value="true" /></pluginClass>');

        // An experimental override reaches rules nothing sets explicitly...
        $this->assertSame([true, true, true], $derived(PluginConfig::fromXml(null, PluginOverrides::parse('experimental=true'))));
        // ...and switches them back off over an experimental XML...
        $this->assertSame([false, false, false], $derived(PluginConfig::fromXml($experimentalXml, PluginOverrides::parse('experimental=false'))));

        // ...but an explicit per-rule value, in the XML or an override, still wins in both directions.
        $explicitXml = new \SimpleXMLElement('<pluginClass><findSerializedQueuedModels value="false" /></pluginClass>');
        $this->assertSame([true, false, true], $derived(PluginConfig::fromXml($explicitXml, PluginOverrides::parse('experimental=true'))));
        $this->assertSame([false, false, true], $derived(PluginConfig::fromXml($experimentalXml, PluginOverrides::parse('findUnconfiguredFilesystemDisks=false findSerializedQueuedModels=false'))));
    }

    #[Test]
    public function blade_override_switches_blade_either_way_and_keeps_the_xml_cache_dir(): void
    {
        $this->assertTrue(PluginConfig::fromXml(null, PluginOverrides::parse('blade=true'))->bladeEnabled);
        $this->assertFalse(PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade /></pluginClass>'), PluginOverrides::parse('blade=false'))->bladeEnabled);

        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade value="false" cacheDir="from-xml" /></pluginClass>'),
            PluginOverrides::parse('blade=true'),
        );

        $this->assertTrue($config->bladeEnabled);
        $this->assertSame('from-xml', $config->bladeCacheDir);
    }

    #[Test]
    public function blade_rejects_an_unknown_attribute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("<blade>: unknown attribute 'cacheDirectory'. Supported attributes: value, cacheDir.");

        PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade cacheDirectory="x" /></pluginClass>'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedXml(): iterable
    {
        yield 'bad bool' => ['<resolveDynamicWhereClauses value="maybe" />'];
        yield 'bad blade value' => ['<blade value="yes" />'];
        yield 'unknown attribute on a later blade element' => ['<blade /><blade typo="1" />'];
        yield 'bad enum' => ['<modelProperties columnFallback="db" />'];
        yield 'prompt injection without value' => ['<findPromptInjection />'];
    }

    #[Test]
    #[DataProvider('malformedXml')]
    public function xml_is_still_validated_when_an_override_wins(string $inner): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginConfig::fromXml(
            new \SimpleXMLElement("<pluginClass>{$inner}</pluginClass>"),
            PluginOverrides::parse('resolveDynamicWhereClauses=true blade=true modelProperties.columnFallback=none findPromptInjection=true'),
        );
    }
}
