<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Config;

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
        public bool $resolveConfigReturnTypes,
        public bool $reportImplicitQueryBuilderCalls,
        public bool $findMissingTranslations,
        public bool $findMissingViews,
        public bool $findSerializedQueuedModels,
        /**
         * Tri-state opt-in/out for the OctaneIncompatibleBinding rule.
         *
         *  - null  → auto-detect (rule registers when laravel/octane is installed)
         *  - true  → force enabled (useful for shared libraries that aim to stay Octane-safe)
         *  - false → force disabled (override even when laravel/octane is installed)
         */
        public ?bool $findOctaneIncompatibleBinding,
        /**
         * Reporting mode for `TaintedLlmPrompt` (untrusted input reaching a
         * `laravel/ai` prompt).
         *
         *  - null  → auto: enforce when the supported laravel/ai integration is enabled
         *  - true  → force enforcement, still only inside that integration gate
         *  - false → explicit opt-out: suppress D-in reporting, still only inside that gate
         */
        public ?bool $findPromptInjection,
        public string $cachePath,
        /** Opt-in Blade template analysis (`<blade enabled="true" />`). */
        public bool $bladeEnabled,
        /** Directory the compiled Blade shadow files live in. Absolute, or relative to the working directory. */
        public string $bladeCacheDir,
        /** Opt-in checking of `view()` call sites against template contracts (`<blade validateViewData="true" />`). */
        public bool $bladeValidateViewData,
        /** Opt-in reporting of a template no statically-provable reference renders (`<blade reportUnusedViews="true" />`). */
        public bool $bladeReportUnusedViews,
        /** Opt-in reporting of a data key the rendered template never reads (`<blade reportUnusedViewData="true" />`). */
        public bool $bladeReportUnusedViewData,
        /** Opt back in to the `MixedIssue` family inside templates, suppressed by default (`<blade reportMixedIssues="true" />`). */
        public bool $bladeReportMixedIssues,
        public bool $experimental,
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
        $experimental = self::xmlBoolAttr($config?->experimental, 'experimental');
        $findMissingTranslations = self::xmlBoolAttr($config?->findMissingTranslations, 'findMissingTranslations');
        $findMissingViews = self::xmlBoolAttr($config?->findMissingViews, 'findMissingViews');
        // experimental = early access to rules not yet promoted to default; an explicit
        // value always overrides it, in either direction.
        $findSerializedQueuedModels = self::xmlOptionalBoolAttr($config?->findSerializedQueuedModels, 'findSerializedQueuedModels') ?? $experimental;
        $reportImplicitQueryBuilderCalls = self::xmlBoolAttr($config?->reportImplicitQueryBuilderCalls, 'reportImplicitQueryBuilderCalls');
        $findOctaneIncompatibleBinding = self::xmlOptionalBoolAttr($config?->findOctaneIncompatibleBinding, 'findOctaneIncompatibleBinding');
        $findPromptInjection = self::xmlPromptInjectionAttr($config);
        $resolveDynamicWhereClauses = self::xmlBoolAttr($config?->resolveDynamicWhereClauses, 'resolveDynamicWhereClauses', true);
        $resolveConfigReturnTypes = self::xmlBoolAttr($config?->resolveConfigReturnTypes, 'resolveConfigReturnTypes', true);
        $configDirectories = self::xmlNameList($config, 'configDirectory');
        $bladeEnabled = self::xmlBoolAttr($config?->blade, 'blade enabled', false, 'enabled');
        $bladeValidateViewData = self::xmlBoolAttr($config?->blade, 'blade validateViewData', false, 'validateViewData');
        $bladeReportUnusedViews = self::xmlBoolAttr($config?->blade, 'blade reportUnusedViews', false, 'reportUnusedViews');
        $bladeReportUnusedViewData = self::xmlBoolAttr($config?->blade, 'blade reportUnusedViewData', false, 'reportUnusedViewData');
        $bladeReportMixedIssues = self::xmlBoolAttr($config?->blade, 'blade reportMixedIssues', false, 'reportMixedIssues');
        $cachePath = self::resolveCachePath();

        return new self(
            modelPropertiesColumnFallback: $columnFallback,
            configDirectories: $configDirectories,
            resolveDynamicWhereClauses: $resolveDynamicWhereClauses,
            resolveConfigReturnTypes: $resolveConfigReturnTypes,
            reportImplicitQueryBuilderCalls: $reportImplicitQueryBuilderCalls,
            findMissingTranslations: $findMissingTranslations,
            findMissingViews: $findMissingViews,
            findSerializedQueuedModels: $findSerializedQueuedModels,
            findOctaneIncompatibleBinding: $findOctaneIncompatibleBinding,
            findPromptInjection: $findPromptInjection,
            cachePath: $cachePath,
            bladeEnabled: $bladeEnabled,
            bladeCacheDir: self::resolveBladeCacheDir($config, $cachePath),
            bladeValidateViewData: $bladeValidateViewData,
            bladeReportUnusedViews: $bladeReportUnusedViews,
            bladeReportUnusedViewData: $bladeReportUnusedViewData,
            bladeReportMixedIssues: $bladeReportMixedIssues,
            experimental: $experimental,
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
     * Read a boolean attribute of an XML element, `value` unless $attribute says otherwise.
     * Expects `<element value="true" />` or `<element value="false" />`.
     * Returns $default when the element or the attribute is absent.
     * @psalm-pure
     */
    private static function xmlBoolAttr(?\SimpleXMLElement $element, string $name, bool $default = false, string $attribute = 'value'): bool
    {
        if (!$element instanceof \SimpleXMLElement) {
            return $default;
        }

        $value = (string) ($element[$attribute] ?? ($default ? 'true' : 'false'));

        if (!\in_array($value, ['true', 'false'], true)) {
            throw new \InvalidArgumentException("Invalid {$name} value '{$value}'. Valid values: 'true', 'false'.");
        }

        return $value === 'true';
    }

    /**
     * Tri-state variant of {@see self::xmlBoolAttr()} for flags that auto-detect
     * when unset. Returns null when the element is absent so callers can fall back
     * to runtime detection (e.g. `class_exists()`); returns true/false when the
     * user explicitly opts in or out via XML.
     *
     * @psalm-pure
     */
    private static function xmlOptionalBoolAttr(?\SimpleXMLElement $element, string $name): ?bool
    {
        if (!$element instanceof \SimpleXMLElement) {
            return null;
        }

        // SimpleXMLElement returns an empty proxy when accessing a non-existent
        // child via dynamic property syntax, so the instanceof check above does
        // not distinguish "absent" from "present". Use the value attribute as
        // the absence signal: a present element without a value attribute is
        // treated as auto-detect, same as a missing element.
        if (!isset($element['value'])) {
            return null;
        }

        $value = (string) $element['value'];

        if (!\in_array($value, ['true', 'false'], true)) {
            throw new \InvalidArgumentException("Invalid {$name} value '{$value}'. Valid values: 'true', 'false'.");
        }

        return $value === 'true';
    }

    /**
     * Read the prompt-injection mode while distinguishing an omitted element
     * from an explicit false. Unlike the Octane flag, a present element without
     * a value is always a configuration error rather than another spelling of
     * auto, so typos fail loudly.
     *
     */
    private static function xmlPromptInjectionAttr(?\SimpleXMLElement $config): ?bool
    {
        if (!$config instanceof \SimpleXMLElement || !isset($config->findPromptInjection)) {
            return null;
        }

        /** @var \SimpleXMLElement $element */
        $element = $config->findPromptInjection;
        if (!isset($element['value'])) {
            throw new \InvalidArgumentException(
                "<findPromptInjection> requires a `value` attribute of 'true' or 'false'.",
            );
        }

        $value = (string) $element['value'];

        if (!\in_array($value, ['true', 'false'], true)) {
            throw new \InvalidArgumentException("Invalid findPromptInjection value '{$value}'. Valid values: 'true', 'false'.");
        }

        return $value === 'true';
    }

    /**
     * Shadow files default to a subdirectory of the plugin's own cache directory, alongside the
     * generated alias stub and the migration schema cache: `--clear-cache` then drops them too.
     *
     * Deliberately outside the project tree. A shadow that a `<projectFiles>` glob picks up
     * becomes reportable, which both leaks compiled-template issues at their compiled locations
     * and makes Psalm skip taint flows whose source sits in a reportable file. A `cacheDir`
     * pointing inside the project must therefore be excluded from `<projectFiles>` by the user.
     */
    private static function resolveBladeCacheDir(?\SimpleXMLElement $config, string $cachePath): string
    {
        $configured = \rtrim(self::xmlStringAttr($config?->blade, 'cacheDir', ''), \DIRECTORY_SEPARATOR);

        if ($configured !== '') {
            return $configured;
        }

        return $cachePath . \DIRECTORY_SEPARATOR . 'blade';
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
