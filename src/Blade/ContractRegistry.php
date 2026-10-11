<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * View name => template contract, populated at boot and read during analysis by
 * {@see \Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler}.
 *
 * Static for the same reason as {@see ShadowRegistry}: the reader is a Psalm event handler, which
 * Psalm instantiates itself and hands nothing but the event. Populated before Psalm forks its
 * analysis workers, so every worker inherits a complete registry by copy-on-write.
 *
 * Keyed by view NAME rather than path because that is what a call site carries. Two view roots can
 * hold the same name; `FileViewFinder` renders whichever root comes first, so the lowest root index
 * wins here too.
 *
 * @internal
 */
final class ContractRegistry
{
    /**
     * @var array<string, array{0: int, 1: ViewDataContract, 2: array{0: list<string>, 1: bool}|null}>
     *      view name => [view root index, contract, data includes]
     */
    private static array $contracts = [];

    /**
     * @var array<string, list<array{0: string, 1: string}>|null> view name => [class, lowercased
     *      method] of every composer or creator reaching it, null when one is not a plain class
     *      composer. Written at boot, read by {@see ComposerKeyResolver}.
     */
    private static array $composerSources = [];

    /** @var array<string, array<array-key, true>|null> view name => keys every composer supplies, null when unprovable */
    private static array $composedKeys = [];

    /** @var array<string, array<string, array{0: int, 1: string}>> template path => variable name => [line, message] */
    private static array $problems = [];

    /**
     * @param array{0: list<string>, 1: bool}|null $dataIncludes view names the template hands its
     *        whole scope to, and whether one of them could not be resolved; null when the collection
     *        pass was off for this run, which {@see ReadSetResolver} treats as "not proven"
     */
    public static function register(string $viewName, int $rootIndex, ViewDataContract $contract, ?array $dataIncludes = null): void
    {
        $existing = self::$contracts[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$contracts[$viewName] = [$rootIndex, $contract, $dataIncludes];
    }

    /**
     * Null for a view name no template declared — which is every view outside the compiled set.
     */
    public static function contractFor(string $viewName): ?ViewDataContract
    {
        return self::$contracts[$viewName][1] ?? null;
    }

    /**
     * @return array{0: list<string>, 1: bool}|null
     */
    public static function dataIncludesFor(string $viewName): ?array
    {
        return self::$contracts[$viewName][2] ?? null;
    }

    /** @param list<array{0: string, 1: string}>|null $sources */
    public static function registerComposerSources(string $viewName, ?array $sources): void
    {
        self::$composerSources[$viewName] = $sources;
    }

    /** @return array<array-key, list<array{0: string, 1: string}>|null> a numeric view name is an integer key */
    public static function composerSources(): array
    {
        return self::$composerSources;
    }

    /** @param array<array-key, true>|null $keys */
    public static function registerComposedKeys(string $viewName, ?array $keys): void
    {
        self::$composedKeys[$viewName] = $keys;
    }

    /**
     * The data keys the view's composers and creators provably supply: `[]` for a view none reaches,
     * null when any of them is unreadable (or the resolver never ran), which makes every key of the
     * view potentially composer-supplied.
     *
     * @return array<array-key, true>|null
     */
    public static function composedKeys(string $viewName): ?array
    {
        return \array_key_exists($viewName, self::$composerSources) ? (self::$composedKeys[$viewName] ?? null) : [];
    }

    /** @param array<string, array{0: int, 1: string}> $problems variable name => [template line, message] */
    public static function registerProblems(string $templatePath, array $problems): void
    {
        if ($problems !== []) {
            self::$problems[$templatePath] = $problems;
        }
    }

    /** @return list<array{0: int, 1: string}> */
    public static function problemsFor(string $templatePath): array
    {
        return \array_values(self::$problems[$templatePath] ?? []);
    }

    public static function reset(): void
    {
        self::$problems = [];
        self::$contracts = [];
        self::$composerSources = [];
        self::$composedKeys = [];
    }
}
