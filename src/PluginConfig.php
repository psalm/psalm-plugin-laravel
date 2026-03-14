<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin;

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

        $failOnInternalErrorValue = (string) ($config?->failOnInternalError['value'] ?? 'false');

        if (!\in_array($failOnInternalErrorValue, ['true', 'false'], true)) {
            throw new \InvalidArgumentException(
                "Invalid failOnInternalError value '{$failOnInternalErrorValue}'. Valid values: 'true', 'false'.",
            );
        }

        $failOnInternalError = $failOnInternalErrorValue === 'true';

        return new self(
            columnFallback: $columnFallback,
            failOnInternalError: $failOnInternalError,
            cachePath: self::resolveCachePath(),
        );
    }

    public function shouldUseMigrations(): bool
    {
        return $this->columnFallback === ColumnFallback::Migrations;
    }

    private static function resolveCachePath(): string
    {
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        if ($env !== false && $env !== '') {
            return \rtrim($env, \DIRECTORY_SEPARATOR);
        }

        return \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-' . \md5(\getcwd() ?: __DIR__);
    }
}
