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
     * @param list<ExperimentalFeature> $experimentalFeatures
     * @param list<string> $experimentalNotices
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public ColumnFallback $modelPropertiesColumnFallback,
        public array $configDirectories,
        public bool $resolveDynamicWhereClauses,
        public bool $resolveConfigReturnTypes,
        public bool $reportImplicitQueryBuilderCalls,
        public bool $findUndefinedRelations,
        public bool $findMissingTranslations,
        public bool $findMissingViews,
        /**
         * Tri-state opt-in/out for the OctaneIncompatibleBinding rule.
         *
         *  - null  → auto-detect (rule registers when laravel/octane is installed)
         *  - true  → force enabled (useful for shared libraries that aim to stay Octane-safe)
         *  - false → force disabled (override even when laravel/octane is installed)
         */
        public ?bool $findOctaneIncompatibleBinding,
        public string $cachePath,
        public bool $failOnInternalError,
        public bool $experimentalAll,
        public array $experimentalFeatures,
        /**
         * Non-fatal messages collected while parsing `<experimental>` (a childless element with
         * no effect, a graduated/withdrawn feature name). Collected here instead of raised via
         * `trigger_error(E_USER_DEPRECATED)` because Psalm's own CLI installs an error handler
         * that turns every PHP error/warning/deprecation into a thrown exception during a real
         * run — `trigger_error()` inside `fromXml()` would abort the whole analysis, exactly the
         * hard-failure this feature exists to avoid. The caller (`Plugin::__invoke()`) surfaces
         * these via `Psalm\Progress\Progress::warning()` once `$output` is available instead.
         */
        public array $experimentalNotices,
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
        $reportImplicitQueryBuilderCalls = self::xmlBoolAttr($config?->reportImplicitQueryBuilderCalls, 'reportImplicitQueryBuilderCalls');
        $findUndefinedRelations = self::xmlBoolAttr($config?->findUndefinedRelations, 'findUndefinedRelations');
        $findOctaneIncompatibleBinding = self::xmlOptionalBoolAttr($config?->findOctaneIncompatibleBinding, 'findOctaneIncompatibleBinding');
        $resolveDynamicWhereClauses = self::xmlBoolAttr($config?->resolveDynamicWhereClauses, 'resolveDynamicWhereClauses', true);
        $resolveConfigReturnTypes = self::xmlBoolAttr($config?->resolveConfigReturnTypes, 'resolveConfigReturnTypes', true);
        $configDirectories = self::xmlNameList($config, 'configDirectory');
        $experimental = self::xmlExperimentalFeatures($config);

        return new self(
            modelPropertiesColumnFallback: $columnFallback,
            configDirectories: $configDirectories,
            resolveDynamicWhereClauses: $resolveDynamicWhereClauses,
            resolveConfigReturnTypes: $resolveConfigReturnTypes,
            reportImplicitQueryBuilderCalls: $reportImplicitQueryBuilderCalls,
            findUndefinedRelations: $findUndefinedRelations,
            findMissingTranslations: $findMissingTranslations,
            findMissingViews: $findMissingViews,
            findOctaneIncompatibleBinding: $findOctaneIncompatibleBinding,
            cachePath: self::resolveCachePath(),
            failOnInternalError: $failOnInternalError,
            experimentalAll: $experimental['all'],
            experimentalFeatures: $experimental['features'],
            experimentalNotices: $experimental['notices'],
        );
    }

    /** @psalm-mutation-free */
    public function shouldUseMigrations(): bool
    {
        return $this->modelPropertiesColumnFallback === ColumnFallback::Migrations;
    }

    /**
     * Whether $feature is enabled, either by name or via `<experimental all="true" />`.
     * @psalm-mutation-free
     */
    public function isExperimentEnabled(ExperimentalFeature $feature): bool
    {
        return \in_array($feature, $this->activeExperimentalFeatures(), true);
    }

    /**
     * Every experimental feature actually active: every case when `all="true"`, otherwise
     * the named subset. Single source of truth for what `all` resolves to, shared by
     * {@see self::isExperimentEnabled()} and by callers that need the resolved list itself
     * (e.g. `Plugin::reportActiveExperiments()`).
     *
     * @return list<ExperimentalFeature>
     *
     * @psalm-mutation-free
     */
    public function activeExperimentalFeatures(): array
    {
        return $this->experimentalAll ? ExperimentalFeature::cases() : $this->experimentalFeatures;
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
     * Parse `<experimental all="true">` / `<experimental><feature name="..."/></experimental>`.
     *
     * Absent element → nothing enabled, no notice (the common case). `all="true"` enables
     * every case in {@see ExperimentalFeature}; `<feature>` children are unioned with it
     * (redundant but harmless once `all` is set). Present-but-childless with no `all`
     * attribute is very likely a mistake, so it collects a deprecation-style notice rather
     * than silently doing nothing. Duplicate `<feature>` names dedupe silently.
     *
     * Notices are collected into the return value rather than raised via
     * `trigger_error(E_USER_DEPRECATED)`: Psalm's CLI installs an error handler that turns
     * every PHP error/warning/deprecation into a thrown exception during a real run, so
     * calling `trigger_error()` from here would abort the whole analysis — exactly the
     * hard-failure a "soft" notice is supposed to avoid. `Plugin::__invoke()` surfaces the
     * collected messages via `Progress::warning()` once `$output` is available instead.
     *
     * @return array{all: bool, features: list<ExperimentalFeature>, notices: list<string>}
     */
    private static function xmlExperimentalFeatures(?\SimpleXMLElement $config): array
    {
        // <experimental> has no attribute of its own to lean on when childless (unlike
        // xmlOptionalBoolAttr's `value`), so isset() is the only reliable "is it actually
        // there" signal — accessing a missing child via -> returns an empty proxy that also
        // satisfies `instanceof SimpleXMLElement`.
        if (!$config instanceof \SimpleXMLElement || !isset($config->experimental)) {
            return ['all' => false, 'features' => [], 'notices' => []];
        }

        /** @psalm-var \SimpleXMLElement $element */
        $element = $config->experimental;

        $allValue = (string) ($element['all'] ?? 'false');

        if (!\in_array($allValue, ['true', 'false'], true)) {
            throw new \InvalidArgumentException(
                "Invalid experimental all value '{$allValue}'. Valid values: 'true', 'false'.",
            );
        }

        $all = $allValue === 'true';

        /** @psalm-var iterable<\SimpleXMLElement> $children */
        $children = $element->feature;

        $features = [];
        $notices = [];
        $sawChild = false;

        foreach ($children as $node) {
            $sawChild = true;
            $name = (string) ($node['name'] ?? '');

            if ($name === '') {
                throw new \InvalidArgumentException(
                    '<feature> requires a non-empty `name` attribute, e.g. <feature name="modelToArrayShape" />.',
                );
            }

            $feature = self::resolveExperimentalFeatureName($name, $notices);

            if ($feature instanceof ExperimentalFeature && !\in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }

        if (!$all && !$sawChild) {
            $notices[] = '<experimental /> has no effect: it has no <feature> children and no all="true" attribute. '
                . 'Remove it, or see docs/config.md for how to enable a specific feature.';
        }

        return ['all' => $all, 'features' => $features, 'notices' => $notices];
    }

    /**
     * Classify one `<feature name="...">` value. A live case is returned as-is. A graduated
     * or withdrawn name appends a deprecation-style notice to $notices and returns null —
     * dropped, not rejected, so upgrading the plugin never turns a previously-valid
     * psalm.xml into a hard failure. Any other name throws, listing every valid name plus a
     * nearest-match hint for typos.
     *
     * @param list<string> $notices
     */
    private static function resolveExperimentalFeatureName(string $name, array &$notices): ?ExperimentalFeature
    {
        $feature = ExperimentalFeature::tryFrom($name);

        if ($feature !== null) {
            return $feature;
        }

        $graduatedVersion = ExperimentalFeature::graduatedIn($name);

        if ($graduatedVersion !== null) {
            $notices[] = "Experimental feature '{$name}' graduated to stable in v{$graduatedVersion} and no longer "
                . 'needs <experimental>. Remove it from psalm.xml.';

            return null;
        }

        $withdrawnReason = ExperimentalFeature::withdrawnBecause($name);

        if ($withdrawnReason !== null) {
            $notices[] = "Experimental feature '{$name}' was withdrawn ({$withdrawnReason}) and no longer exists. "
                . 'Remove it from psalm.xml.';

            return null;
        }

        $valid = \implode(', ', \array_map(
            static fn(ExperimentalFeature $case): string => "'{$case->value}'",
            ExperimentalFeature::cases(),
        ));

        throw new \InvalidArgumentException(
            "Unknown experimental feature '{$name}'. Did you mean '" . self::nearestExperimentalFeatureName($name)
            . "'? Valid values: {$valid}.",
        );
    }

    /**
     * Closest valid feature name by edit distance, for a "did you mean" hint on typos.
     * @psalm-pure
     */
    private static function nearestExperimentalFeatureName(string $name): string
    {
        return self::closestByLevenshtein(
            $name,
            \array_map(static fn(ExperimentalFeature $case): string => $case->value, ExperimentalFeature::cases()),
        );
    }

    /**
     * Closest string in $haystack to $needle by edit distance. Generic on purpose — separated
     * from {@see self::nearestExperimentalFeatureName()} so the actual "pick the closer of two
     * candidates" comparison is unit-testable with a synthetic multi-candidate list. With only
     * one live {@see ExperimentalFeature} case today, that comparison never has a second
     * candidate to prefer over the first when driven through `fromXml()` alone.
     *
     * @param non-empty-list<string> $haystack
     *
     * @psalm-pure
     */
    private static function closestByLevenshtein(string $needle, array $haystack): string
    {
        $closest = $haystack[0];
        $closestDistance = \levenshtein($needle, $closest);

        foreach ($haystack as $candidate) {
            $distance = \levenshtein($needle, $candidate);

            if ($distance < $closestDistance) {
                $closest = $candidate;
                $closestDistance = $distance;
            }
        }

        return $closest;
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
