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
    public function blade_is_disabled_when_the_element_carries_no_enabled_attribute(): void
    {
        // A bare <blade cacheDir="..."/> must not turn the feature on: the
        // element alone is not consent, the attribute is.
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade cacheDir="tmp/shadows" /></pluginClass>'));

        $this->assertFalse($config->bladeEnabled);
    }

    #[Test]
    public function blade_is_enabled_by_the_enabled_attribute(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade enabled="true" /></pluginClass>'));

        $this->assertTrue($config->bladeEnabled);
    }

    #[Test]
    public function blade_enabled_false_is_accepted(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade enabled="false" /></pluginClass>'));

        $this->assertFalse($config->bladeEnabled);
    }

    #[Test]
    public function blade_enabled_rejects_a_non_boolean_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Invalid blade enabled value 'yes'/");

        PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade enabled="yes" /></pluginClass>'));
    }

    #[Test]
    public function blade_cache_dir_defaults_to_a_subdirectory_of_the_plugin_cache(): void
    {
        $config = PluginConfig::fromXml(new \SimpleXMLElement('<pluginClass><blade enabled="true" /></pluginClass>'));

        $this->assertSame($config->cachePath . \DIRECTORY_SEPARATOR . 'blade', $config->bladeCacheDir);
    }

    #[Test]
    public function blade_cache_dir_attribute_wins_over_the_default(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><blade enabled="true" cacheDir="build/blade-shadows/" /></pluginClass>'),
        );

        $this->assertSame('build/blade-shadows', $config->bladeCacheDir);
    }
}
