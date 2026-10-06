<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli\Diagnose;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\PluginSettings;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Config\Setting;

#[CoversClass(PluginSettings::class)]
#[CoversClass(PluginConfig::class)]
final class PluginSettingsTest extends TestCase
{
    #[Test]
    public function every_schema_key_gets_its_effective_value_and_the_layer_that_set_it(): void
    {
        $rows = $this->resolve(
            xmlPlugin: '<blade /><experimental value="true" />',
            env: 'findMissingViews=true',
            cli: ['modelProperties.columnFallback=none', 'blade=false'],
        );

        $this->assertSame(\array_map(static fn(Setting $setting): string => $setting->key, Setting::all()), \array_keys($rows));
        $this->assertSame(['false', 'cli'], $rows['blade']);
        $this->assertSame(['true', 'env'], $rows['findMissingViews']);
        $this->assertSame(['true', 'xml'], $rows['experimental']);
        $this->assertSame(['true', 'derived (experimental)'], $rows['findUnregisteredRouteNames']);
        $this->assertSame(['none', 'cli'], $rows['modelProperties.columnFallback']);
        $this->assertSame(['true', 'default'], $rows['resolveDynamicWhereClauses']);
        $this->assertSame(['auto', 'default'], $rows['findOctaneIncompatibleBinding']);
    }

    #[Test]
    public function the_last_of_blade_flag_and_plugin_option_wins_and_a_default_stays_a_default(): void
    {
        $rows = $this->resolve(xmlPlugin: '', env: null, cli: ['blade=false', 'blade=true']);

        $this->assertSame(['true', 'cli'], $rows['blade']);
        $this->assertSame(['false', 'default'], $rows['findUnregisteredRouteNames']);
    }

    #[Test]
    public function an_explicit_xml_value_beats_experimental_and_an_empty_config_list_reads_as_the_app_default(): void
    {
        $rows = $this->resolve(xmlPlugin: '<experimental value="true" /><findSerializedQueuedModels value="false" />', env: null, cli: []);

        $this->assertSame(['false', 'xml'], $rows['findSerializedQueuedModels']);
        $this->assertSame("(none: the app's config path)", $rows['configDirectory'][0]);
    }

    #[Test]
    public function the_blade_cache_dir_default_is_described_not_resolved_through_psalm(): void
    {
        $rows = $this->resolve(xmlPlugin: '', env: null, cli: []);

        $this->assertSame(['(default: <psalm cache dir>/plugin-laravel/blade)', 'default'], $rows['blade.cacheDir']);
        $this->assertSame(['/tmp/blade shadows', 'cli'], $this->resolve(xmlPlugin: '', env: null, cli: ['blade.cacheDir=/tmp/blade shadows'])['blade.cacheDir']);
    }

    #[Test]
    public function an_invalid_override_or_xml_value_throws_a_message_naming_it(): void
    {
        try {
            $this->resolve(xmlPlugin: '', env: null, cli: ['blade=maybe']);
            $this->fail('An invalid override must throw.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString("invalid value 'maybe' for key 'blade'", $invalidArgumentException->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid experimental value 'yes'");
        $this->resolve(xmlPlugin: '<experimental value="yes" />', env: null, cli: []);
    }

    /**
     * @param list<string> $cli
     *
     * @return array<string, array{string, string}> value and source by key
     */
    private function resolve(string $xmlPlugin, ?string $env, array $cli): array
    {
        $xml = \simplexml_load_string(
            '<psalm xmlns="https://getpsalm.org/schema/config"><plugins><pluginClass class="Psalm\LaravelPlugin\Plugin">'
            . $xmlPlugin . '</pluginClass></plugins></psalm>',
        );
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);

        $rows = [];
        foreach (PluginSettings::resolve($xml, $env, $cli) as $row) {
            $rows[$row['key']] = [$row['value'], $row['source']];
        }

        return $rows;
    }
}
