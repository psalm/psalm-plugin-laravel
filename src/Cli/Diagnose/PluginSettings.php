<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Config\PluginOverrides;
use Psalm\LaravelPlugin\Config\Setting;

/**
 * Resolves the plugin settings the way {@see \Psalm\LaravelPlugin\Plugin} does, via the same
 * {@see PluginConfig::fromXml()}, and attributes each effective value to the layer that set it.
 *
 * @internal
 */
final class PluginSettings
{
    private const PLUGIN_CLASS = 'Psalm\\LaravelPlugin\\Plugin';

    /**
     * One row per schema key, in schema order.
     *
     * @param list<string> $cliTokens `KEY=VALUE` tokens of `--plugin-option` / `--blade`, in order.
     *
     * @return list<array{key: string, value: string, source: string}>
     *
     * @throws \InvalidArgumentException When an override or a plugin XML value is invalid.
     */
    public static function resolve(?\SimpleXMLElement $psalmXml, ?string $envRaw, array $cliTokens): array
    {
        $env = PluginOverrides::fromEnv($envRaw);
        $cli = PluginOverrides::parse($cliTokens, '--plugin-option');
        $element = self::pluginElement($psalmXml);
        $config = PluginConfig::fromXml($element, $env->over($cli));
        $rows = [];

        foreach (Setting::all() as $setting) {
            $source = match (true) {
                $cli->has($setting->key) => 'cli',
                $env->has($setting->key) => 'env',
                self::inXml($element, $setting->key) => 'xml',
                // The schema marks the keys whose default is "whatever `experimental` says".
                $setting->default === 'experimental' && $config->experimental => 'derived (experimental)',
                default => 'default',
            };

            $rows[] = [
                'key' => $setting->key,
                // The resolved path would be a temp dir here: Psalm's cache dir is only known inside a psalm run.
                'value' => $setting->key === 'blade.cacheDir' && $source === 'default'
                    ? '(default: <psalm cache dir>/plugin-laravel/blade)'
                    : $config->display($setting->key),
                'source' => $source,
            ];
        }

        return $rows;
    }

    private static function pluginElement(?\SimpleXMLElement $psalmXml): ?\SimpleXMLElement
    {
        if (!$psalmXml instanceof \SimpleXMLElement) {
            return null;
        }

        // A missing <plugins> reads as an empty proxy whose `pluginClass` is null, not an empty list.
        /** @psalm-var iterable<\SimpleXMLElement>|null $plugins */
        $plugins = $psalmXml->plugins?->pluginClass;

        foreach ($plugins ?? [] as $plugin) {
            if ((string) $plugin['class'] === self::PLUGIN_CLASS) {
                return $plugin;
            }
        }

        return null;
    }

    /** Whether the XML sets the key itself, which is not the same as the resolved value differing from the default. */
    private static function inXml(?\SimpleXMLElement $config, string $key): bool
    {
        if (!$config instanceof \SimpleXMLElement) {
            return false;
        }

        [$element, $attribute] = \explode('.', $key, 2) + [1 => null];

        if ($attribute !== null) {
            /** @psalm-var \SimpleXMLElement $parent */
            $parent = $config->{$element};

            return (string) ($parent[$attribute] ?? '') !== '';
        }

        // `<blade />` and `<configDirectory name=".." />` act by presence; every other key needs its `value`.
        return $key === 'blade' || $key === 'configDirectory' ? isset($config->{$key}) : isset($config->{$key}['value']);
    }
}
