<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * Per-run plugin setting overrides, applied as a patch to a copy of the plugin's psalm.xml element so
 * {@see PluginConfig::fromXml()} stays the only reader and validator of values.
 * A key mirrors the XML: `foo=v` sets `<foo value="v"/>`, `foo.bar=v` sets `<foo bar="v"/>`.
 *
 * @internal
 */
final class PluginOverrides
{
    /**
     * Whitespace-separated `KEY=VALUE` tokens. `psalm-laravel analyze` appends its `--plugin-option`
     * values here for the child psalm, because `vendor/bin/psalm` rejects unknown flags.
     * A real process variable, not a Laravel `.env` entry: it is read before the app boots.
     */
    public const ENV_VAR = 'PSALM_LARAVEL_OPTIONS';

    /** `configDirectory` is absent: it is a list of project layout facts, not a per-run switch. */
    public const KEYS = [
        'modelProperties.columnFallback',
        'resolveDynamicWhereClauses',
        'resolveConfigReturnTypes',
        'reportImplicitQueryBuilderCalls',
        'findMissingTranslations',
        'findMissingViews',
        'findUnconfiguredFilesystemDisks',
        'findUnregisteredRouteNames',
        'findSerializedQueuedModels',
        'findOctaneIncompatibleBinding',
        'findPromptInjection',
        'blade',
        'blade.cacheDir',
        'blade.validateViewData',
        'blade.reportUnusedViewData',
        'blade.reportMixedIssues',
        'experimental',
        'failOnInternalError',
    ];

    /**
     * Checks key names and token shape only; values are checked by fromXml(). A repeated key is
     * last-wins, but every token is checked, so a shadowed typo still fails.
     *
     * @return array<string, non-empty-string>
     *
     * @throws \InvalidArgumentException
     *
     * @psalm-pure
     */
    public static function parse(string $raw): array
    {
        $overrides = [];
        $tokens = \preg_split('/\s+/', $raw, -1, \PREG_SPLIT_NO_EMPTY);

        foreach ($tokens === false ? [] : $tokens as $token) {
            [$key, $value] = \explode('=', $token, 2) + [1 => ''];

            if ($key === '' || $value === '') {
                throw new \InvalidArgumentException("Plugin option '{$token}' is invalid: expected KEY=VALUE with a non-empty value.");
            }

            if (!\in_array($key, self::KEYS, true)) {
                throw new \InvalidArgumentException("Unknown plugin option '{$key}'. Supported keys: " . \implode(', ', self::KEYS) . '.');
            }

            $overrides[$key] = $value;
        }

        return $overrides;
    }

    /**
     * @param array<string, non-empty-string> $overrides From {@see self::parse()}.
     *
     * @throws \InvalidArgumentException A setting is invalid, in the XML or in an override.
     */
    public static function configure(?\SimpleXMLElement $config, array $overrides): PluginConfig
    {
        if ($overrides === []) {
            return PluginConfig::fromXml($config);
        }

        try {
            return PluginConfig::fromXml(self::patch($config, $overrides));
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $applied = \implode(' ', \array_map(
                static fn(string $key, string $value): string => "{$key}={$value}",
                \array_keys($overrides),
                $overrides,
            ));

            throw new \InvalidArgumentException("{$invalidArgumentException->getMessage()} (plugin options in effect: {$applied})", 0, $invalidArgumentException);
        }
    }

    /** @param non-empty-array<string, non-empty-string> $overrides */
    private static function patch(?\SimpleXMLElement $config, array $overrides): \SimpleXMLElement
    {
        // A fresh root with the children copied, never a clone: Psalm passes `<pluginClass>->children()`,
        // whose DOM node is its first child. Copies keep their namespace, which property reads ignore.
        $document = new \DOMDocument();
        $root = $document->appendChild($document->createElement('pluginClass'));

        /** @psalm-var iterable<\SimpleXMLElement> $children */
        $children = $config ?? [];

        foreach ($children as $child) {
            $root->appendChild($document->importNode(\dom_import_simplexml($child), true));
        }

        $patched = \simplexml_import_dom($root) ?? throw new \LogicException('Cannot import the plugin config.');

        foreach ($overrides as $key => $value) {
            [$name, $attribute] = \explode('.', $key, 2) + [1 => 'value'];

            /** @psalm-var \SimpleXMLElement $matches */
            $matches = $patched->{$name};
            // The first match, as PluginConfig reads it.
            $element = $matches[0];

            if ($element === null) {
                $element = $patched->addChild($name) ?? throw new \LogicException("Cannot add <{$name}>.");

                // `<blade />` enables Blade by its presence: a sub-setting alone must leave it off.
                if ($name === 'blade') {
                    $element->addAttribute('value', 'false');
                }
            }

            unset($element[$attribute]);
            $element->addAttribute($attribute, $value);
        }

        return $patched;
    }
}
