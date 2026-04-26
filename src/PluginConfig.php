<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Psalm\Config;

/**
 * Immutable value object holding all plugin configuration.
 *
 * Built once from the `<pluginClass>` XML element in psalm.xml,
 * then threaded through to handlers that need it.
 *
 * @internal
 */
final readonly class PluginConfig
{
    /**
     * @param list<string> $configDirectories
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public ColumnFallback $modelPropertiesColumnFallback,
        public array $configDirectories,
        public bool $resolveDynamicWhereClauses,
        public bool $findMissingTranslations,
        public bool $findMissingViews,
        public string $cachePath,
        public bool $failOnInternalError,
    ) {}

    public static function fromXml(?\SimpleXMLElement $config): self
    {
        $columnFallbackValue = self::xmlStringAttr($config?->modelProperties, 'columnFallback', 'migrations');
        $columnFallback = ColumnFallback::tryFrom($columnFallbackValue);

        if ($columnFallback === null) {
            $valid = \implode(', ', \array_map(
                static fn(ColumnFallback $case): string => "'{$case->value}'",
                ColumnFallback::cases(),
            ));

            throw new \InvalidArgumentException(
                "Invalid columnFallback value '{$columnFallbackValue}'. Valid values: {$valid}.",
            );
        }

        $failOnInternalError = self::xmlBoolAttr($config?->failOnInternalError, 'failOnInternalError');
        $findMissingTranslations = self::xmlBoolAttr($config?->findMissingTranslations, 'findMissingTranslations');
        $findMissingViews = self::xmlBoolAttr($config?->findMissingViews, 'findMissingViews');
        $resolveDynamicWhereClauses = self::xmlBoolAttr($config?->resolveDynamicWhereClauses, 'resolveDynamicWhereClauses', true);
        $configDirectories = self::xmlNameList($config, 'configDirectory');

        return new self(
            modelPropertiesColumnFallback: $columnFallback,
            configDirectories: $configDirectories,
            resolveDynamicWhereClauses: $resolveDynamicWhereClauses,
            findMissingTranslations: $findMissingTranslations,
            findMissingViews: $findMissingViews,
            cachePath: self::resolveCachePath(),
            failOnInternalError: $failOnInternalError,
        );
    }

    /** @psalm-mutation-free */
    public function shouldUseMigrations(): bool
    {
        return $this->modelPropertiesColumnFallback === ColumnFallback::Migrations;
    }

    /**
     * Read repeating elements like `<configDirectory name="..." />` as a list of `name` values.
     *
     * Throws on any element that lacks a non-empty `name` attribute so user typos like
     * `<configDirectory path="..." />` (wrong attribute) or a stray `<configDirectory />`
     * surface immediately instead of silently falling back to the default config_path().
     *
     * The `iterable<\SimpleXMLElement>` annotation on `$children` is necessary because
     * Psalm's SimpleXMLElement stub types dynamic-property iteration as `mixed`.
     *
     * @return list<string>
     */
    private static function xmlNameList(?\SimpleXMLElement $config, string $element): array
    {
        if (!$config instanceof \SimpleXMLElement) {
            return [];
        }

        /** @psalm-var iterable<\SimpleXMLElement> $children */
        $children = $config->{$element};

        $values = [];

        foreach ($children as $node) {
            $value = (string) ($node['name'] ?? '');

            if ($value === '') {
                throw new \InvalidArgumentException(
                    "<{$element}> requires a non-empty `name` attribute, e.g. <{$element} name=\"app/Config\" />.",
                );
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Read a named attribute of an XML element as a string.
     * Returns $default when the element is absent or the attribute is missing.
     * @psalm-pure
     */
    private static function xmlStringAttr(?\SimpleXMLElement $element, string $attribute, string $default): string
    {
        if (!$element instanceof \SimpleXMLElement) {
            return $default;
        }

        return (string) ($element[$attribute] ?? $default);
    }

    /**
     * Read the `value` attribute of an XML element as a boolean.
     * Expects `<element value="true" />` or `<element value="false" />`.
     * Returns $default when the element is absent.
     * @psalm-pure
     */
    private static function xmlBoolAttr(?\SimpleXMLElement $element, string $name, bool $default = false): bool
    {
        if (!$element instanceof \SimpleXMLElement) {
            return $default;
        }

        $value = (string) ($element['value'] ?? ($default ? 'true' : 'false'));

        if (!\in_array($value, ['true', 'false'], true)) {
            throw new \InvalidArgumentException(
                "Invalid {$name} value '{$value}'. Valid values: 'true', 'false'.",
            );
        }

        return $value === 'true';
    }

    private static function resolveCachePath(): string
    {
        // Deprecated env var override — still works, but users should rely on
        // the automatic Psalm cache directory instead
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        if (\is_string($env) && $env !== '') {
            \trigger_error(
                'PSALM_LARAVEL_PLUGIN_CACHE_PATH is deprecated and will be removed in v5. '
                    . "The plugin now uses Psalm's cache directory automatically.",
                \E_USER_DEPRECATED,
            );
            return \rtrim($env, \DIRECTORY_SEPARATOR);
        }

        // Use Psalm's project-specific cache directory with a plugin subdirectory.
        // This keeps all Psalm-related caches together, and --clear-cache removes
        // plugin caches along with Psalm's.
        try {
            $psalmCacheDir = Config::getInstance()->getCacheDirectory();

            if ($psalmCacheDir !== null) {
                return $psalmCacheDir . \DIRECTORY_SEPARATOR . 'plugin-laravel';
            }
        } catch (\UnexpectedValueException) {
            // Config::getInstance() throws when Psalm config is not yet initialized
            // (e.g. during unit tests) — fall back to temp directory
        }

        return \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-' . \md5(\getcwd() ?: __DIR__);
    }
}
