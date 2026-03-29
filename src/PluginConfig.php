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
 * @psalm-immutable
 * @internal
 */
final readonly class PluginConfig
{
    private function __construct(
        public ColumnFallback $columnFallback,
        public bool $failOnInternalError,
        public bool $findMissingTranslations,
        public bool $findMissingViews,
        public string $cachePath,
    ) {}

    public static function fromXml(?\SimpleXMLElement $config): self
    {
        $columnFallbackValue = (string) ($config?->modelProperties['columnFallback'] ?? 'migrations');
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

        $failOnInternalError = self::parseBool($config, 'failOnInternalError');
        $findMissingTranslations = self::parseBool($config, 'findMissingTranslations');
        $findMissingViews = self::parseBool($config, 'findMissingViews');

        return new self(
            columnFallback: $columnFallback,
            failOnInternalError: $failOnInternalError,
            findMissingTranslations: $findMissingTranslations,
            findMissingViews: $findMissingViews,
            cachePath: self::resolveCachePath(),
        );
    }

    public function shouldUseMigrations(): bool
    {
        return $this->columnFallback === ColumnFallback::Migrations;
    }

    /**
     * Parse a boolean XML config option with validation.
     *
     * Expects `<optionName value="true" />` or `<optionName value="false" />`.
     * Defaults to false when the option is absent.
     *
     * @psalm-pure
     */
    private static function parseBool(?\SimpleXMLElement $config, string $name): bool
    {
        $value = (string) ($config?->{$name}['value'] ?? 'false');

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

        if ($env !== false && $env !== '') {
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
