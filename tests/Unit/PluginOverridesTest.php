<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\ColumnFallback;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Config\PluginOverrides;

#[CoversClass(PluginOverrides::class)]
final class PluginOverridesTest extends TestCase
{
    #[Test]
    public function parse_splits_on_whitespace_and_a_repeated_key_is_last_wins(): void
    {
        $this->assertSame([], PluginOverrides::parse(" \t\n "));
        $this->assertSame(
            ['blade' => 'false', 'blade.cacheDir' => 'a=b'],
            PluginOverrides::parse("blade=true\tblade.cacheDir=a=b  blade=false"),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedTokens(): iterable
    {
        yield 'missing equals' => ['blade', 'expected KEY=VALUE'];
        yield 'empty value' => ['blade=', 'expected KEY=VALUE'];
        yield 'empty key' => ['=true', 'expected KEY=VALUE'];
        yield 'unknown key lists the supported ones' => ['bladee=true', "Unknown plugin option 'bladee'. Supported keys: modelProperties.columnFallback, "];
        yield 'list key is not overridable' => ['configDirectory=a', "Unknown plugin option 'configDirectory'"];
        yield 'a shadowed typo still fails' => ['nope=1 blade=true', "Unknown plugin option 'nope'"];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function parse_rejects_a_malformed_token(string $raw, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PluginOverrides::parse($raw);
    }

    /** @return iterable<string, array{string, \Closure(PluginConfig): mixed, mixed}> */
    public static function everyKey(): iterable
    {
        yield 'modelProperties.columnFallback' => ['none', static fn(PluginConfig $c): ColumnFallback => $c->modelPropertiesColumnFallback, ColumnFallback::None];
        yield 'resolveDynamicWhereClauses' => ['false', static fn(PluginConfig $c): bool => $c->resolveDynamicWhereClauses, false];
        yield 'resolveConfigReturnTypes' => ['false', static fn(PluginConfig $c): bool => $c->resolveConfigReturnTypes, false];
        yield 'reportImplicitQueryBuilderCalls' => ['true', static fn(PluginConfig $c): bool => $c->reportImplicitQueryBuilderCalls, true];
        yield 'findMissingTranslations' => ['true', static fn(PluginConfig $c): bool => $c->findMissingTranslations, true];
        yield 'findMissingViews' => ['true', static fn(PluginConfig $c): bool => $c->findMissingViews, true];
        yield 'findUnconfiguredFilesystemDisks' => ['true', static fn(PluginConfig $c): bool => $c->findUnconfiguredFilesystemDisks, true];
        yield 'findUnregisteredRouteNames' => ['true', static fn(PluginConfig $c): bool => $c->findUnregisteredRouteNames, true];
        yield 'findSerializedQueuedModels' => ['true', static fn(PluginConfig $c): bool => $c->findSerializedQueuedModels, true];
        yield 'findOctaneIncompatibleBinding' => ['false', static fn(PluginConfig $c): ?bool => $c->findOctaneIncompatibleBinding, false];
        yield 'findPromptInjection' => ['false', static fn(PluginConfig $c): ?bool => $c->findPromptInjection, false];
        yield 'blade' => ['true', static fn(PluginConfig $c): bool => $c->bladeEnabled, true];
        yield 'blade.cacheDir' => ['/tmp/shadows/', static fn(PluginConfig $c): string => $c->bladeCacheDir, '/tmp/shadows'];
        yield 'blade.validateViewData' => ['true', static fn(PluginConfig $c): bool => $c->bladeValidateViewData, true];
        yield 'blade.reportUnusedViewData' => ['true', static fn(PluginConfig $c): bool => $c->bladeReportUnusedViewData, true];
        yield 'blade.reportMixedIssues' => ['true', static fn(PluginConfig $c): bool => $c->bladeReportMixedIssues, true];
        yield 'experimental' => ['true', static fn(PluginConfig $c): bool => $c->experimental, true];
        yield 'failOnInternalError' => ['true', static fn(PluginConfig $c): bool => $c->failOnInternalError, true];
    }

    /** @param \Closure(PluginConfig): mixed $read */
    #[Test]
    #[DataProvider('everyKey')]
    public function every_key_reaches_its_setting(string $value, \Closure $read, mixed $expected): void
    {
        $key = (string) $this->dataName();

        $this->assertNotSame($expected, $read(PluginConfig::fromXml(null)), 'the override must differ from the default');
        $this->assertSame($expected, $read(PluginOverrides::configure(null, [$key => $value])));
    }

    #[Test]
    public function every_key_has_a_case_above(): void
    {
        $this->assertSame(PluginOverrides::KEYS, \array_keys(\iterator_to_array(self::everyKey())));
    }

    #[Test]
    public function patches_a_copy_of_the_namespaced_element_psalm_passes(): void
    {
        $psalm = new \SimpleXMLElement(
            '<psalm xmlns="https://getpsalm.org/schema/config"><plugins><pluginClass class="Psalm\LaravelPlugin\Plugin">'
            . '<findMissingViews value="true" /><blade value="false" cacheDir="xml-dir" />'
            . '<configDirectory name="a" /><configDirectory name="b" />'
            . '</pluginClass></plugins></psalm>',
        );
        // Psalm's own extraction, see Config::loadFromXML().
        $original = $psalm->plugins->pluginClass->children();
        $before = $psalm->asXML();

        $config = PluginOverrides::configure($original, ['blade' => 'true', 'findMissingViews' => 'false', 'experimental' => 'true']);

        $this->assertTrue($config->bladeEnabled);
        $this->assertSame('xml-dir', $config->bladeCacheDir);
        $this->assertFalse($config->findMissingViews);
        $this->assertTrue($config->experimental);
        $this->assertSame(['a', 'b'], $config->configDirectories);
        $this->assertSame($before, $psalm->asXML(), 'the element Psalm owns must not be mutated');
    }

    #[Test]
    public function blade_sub_settings_never_enable_blade(): void
    {
        $subSettings = ['blade.cacheDir' => '/tmp/x', 'blade.validateViewData' => 'true'];

        $this->assertFalse(PluginOverrides::configure(null, $subSettings)->bladeEnabled);
        $this->assertFalse(PluginOverrides::configure(new \SimpleXMLElement('<pluginClass><blade value="false" /></pluginClass>'), $subSettings)->bladeEnabled);
        $this->assertTrue(PluginOverrides::configure(new \SimpleXMLElement('<pluginClass><blade /></pluginClass>'), $subSettings)->bladeEnabled);

        $config = PluginOverrides::configure(null, ['blade.cacheDir' => '/tmp/x', 'blade' => 'true']);
        $this->assertTrue($config->bladeEnabled);
        $this->assertSame('/tmp/x', $config->bladeCacheDir);
    }

    #[Test]
    public function an_explicit_xml_rule_still_beats_an_experimental_override(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findSerializedQueuedModels value="false" /></pluginClass>');

        $config = PluginOverrides::configure($xml, ['experimental' => 'true']);

        $this->assertFalse($config->findSerializedQueuedModels);
        $this->assertTrue($config->findUnregisteredRouteNames);
    }

    /** @return iterable<string, array{string, array<string, non-empty-string>, string}> */
    public static function invalidSettings(): iterable
    {
        yield 'bad override value' => ['<pluginClass />', ['findPromptInjection' => 'yes'], "Invalid findPromptInjection value 'yes'. Valid values: 'true', 'false'. (plugin options in effect: findPromptInjection=yes)"];
        yield 'bad enum override' => ['<pluginClass />', ['modelProperties.columnFallback' => 'db'], "Invalid columnFallback value 'db'"];
        yield 'bad XML value next to an override' => ['<pluginClass><findMissingViews value="yes" /></pluginClass>', ['blade' => 'true'], "Invalid findMissingViews value 'yes'. Valid values: 'true', 'false'. (plugin options in effect: blade=true)"];
    }

    /** @param array<string, non-empty-string> $overrides */
    #[Test]
    #[DataProvider('invalidSettings')]
    public function an_invalid_setting_fails_naming_the_overrides_in_effect(string $xml, array $overrides, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PluginOverrides::configure(new \SimpleXMLElement($xml), $overrides);
    }
}
