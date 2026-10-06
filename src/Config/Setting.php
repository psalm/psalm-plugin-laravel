<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

/**
 * One overridable plugin setting: the flat schema behind override validation and `analyze --help`.
 * Keys mirror the XML names; `cachePath` is not one because it only places the plugin's own cache files.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class Setting
{
    /**
     * @param list<string> $values The accepted values of a Bool or Enum setting.
     * @param string       $default Display text for the help; the real default lives in PluginConfig.
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public string $key,
        public SettingType $type,
        public string $default,
        public array $values = [],
    ) {}

    /**
     * @return list<self>
     *
     * @psalm-pure
     */
    public static function all(): array
    {
        return [
            new self('modelProperties.columnFallback', SettingType::Enum, 'migrations', \array_map(
                static fn(ColumnFallback $case): string => $case->value,
                ColumnFallback::cases(),
            )),
            self::flag('resolveDynamicWhereClauses', 'true'),
            self::flag('resolveConfigReturnTypes', 'true'),
            self::flag('reportImplicitQueryBuilderCalls'),
            new self('configDirectory', SettingType::PathList, "none (the app's config path)"),
            self::flag('findMissingTranslations'),
            self::flag('findMissingViews'),
            self::flag('findUnconfiguredFilesystemDisks', 'experimental'),
            self::flag('findUnregisteredRouteNames', 'experimental'),
            self::flag('findSerializedQueuedModels', 'experimental'),
            self::flag('findOctaneIncompatibleBinding', 'auto'),
            self::flag('findPromptInjection', 'auto'),
            self::flag('blade'),
            new self('blade.cacheDir', SettingType::Path, "plugin-laravel/blade in Psalm's cache dir"),
            self::flag('blade.validateViewData'),
            self::flag('blade.reportUnusedViewData'),
            self::flag('blade.reportMixedIssues'),
            self::flag('experimental'),
            self::flag('failOnInternalError'),
        ];
    }

    /** @psalm-pure */
    public static function find(string $key): ?self
    {
        foreach (self::all() as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        return null;
    }

    /**
     * One help line: key, accepted values or type, default.
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        $accepts = $this->values === [] ? $this->type->value : \implode('|', $this->values);

        return \sprintf('  %-32s %-16s (default: %s)', $this->key, $accepts, $this->default);
    }

    /** @psalm-pure */
    private static function flag(string $key, string $default = 'false'): self
    {
        return new self($key, SettingType::Bool, $default, ['true', 'false']);
    }
}
