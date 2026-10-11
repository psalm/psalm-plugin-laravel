<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginConfig;

#[CoversClass(PluginConfig::class)]
final class BladeConfigTest extends TestCase
{
    #[Test]
    public function blade_is_disabled_when_the_element_is_absent(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass />'));

        $this->assertFalse($config->bladeEnabled);
    }

    #[Test]
    public function blade_is_disabled_without_any_xml(): void
    {
        $this->assertFalse(PluginConfig::fromXml(null)->bladeEnabled);
    }

    #[Test]
    public function blade_is_enabled_by_the_bare_element(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade /></pluginClass>'));

        $this->assertTrue($config->bladeEnabled);
    }

    #[Test]
    public function blade_is_enabled_by_an_element_carrying_only_settings(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade cacheDir="tmp/shadows" /></pluginClass>'));

        $this->assertTrue($config->bladeEnabled);
    }

    #[Test]
    public function blade_value_true_is_accepted(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade value="true" /></pluginClass>'));

        $this->assertTrue($config->bladeEnabled);
    }

    #[Test]
    public function blade_value_false_switches_the_present_element_off(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade value="false" validateViewData="true" /></pluginClass>'),
        );

        $this->assertFalse($config->bladeEnabled);
    }

    #[Test]
    public function blade_value_rejects_a_non_boolean_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Invalid blade value 'yes'/");

        PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade value="yes" /></pluginClass>'));
    }

    #[Test]
    public function blade_cache_dir_defaults_to_a_subdirectory_of_the_plugin_cache(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade /></pluginClass>'));

        $this->assertSame($config->cachePath . \DIRECTORY_SEPARATOR . 'blade', $config->bladeCacheDir);
    }

    #[Test]
    public function blade_cache_dir_attribute_wins_over_the_default(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade cacheDir="build/blade-shadows/" /></pluginClass>'),
        );

        $this->assertSame('build/blade-shadows', $config->bladeCacheDir);
    }

    #[Test]
    public function report_mixed_issues_is_disabled_when_the_element_is_absent(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass />'));

        $this->assertFalse($config->bladeReportMixedIssues);
    }

    #[Test]
    public function report_mixed_issues_is_disabled_when_the_element_carries_no_such_attribute(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade /></pluginClass>'));

        $this->assertFalse($config->bladeReportMixedIssues);
    }

    #[Test]
    public function report_mixed_issues_is_enabled_by_the_attribute(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade reportMixedIssues="true" /></pluginClass>'),
        );

        $this->assertTrue($config->bladeReportMixedIssues);
    }

    #[Test]
    public function report_mixed_issues_false_is_accepted(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade reportMixedIssues="false" /></pluginClass>'),
        );

        $this->assertFalse($config->bladeReportMixedIssues);
    }

    #[Test]
    public function report_mixed_issues_rejects_a_non_boolean_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Invalid blade reportMixedIssues value 'yes'/");

        PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade reportMixedIssues="yes" /></pluginClass>'),
        );
    }
}
