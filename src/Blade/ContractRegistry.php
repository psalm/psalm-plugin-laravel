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
     * @var array<string, array{0: int, 1: ViewDataContract, 2: array{0: list<string>, 1: bool}|null, 3: string|false|null}>
     *      view name => [view root index, contract, data includes, extended layout]
     */
    private static array $contracts = [];

    /** @var array<string, true> */
    private static array $composed = [];

    /** @var array<string, array<string, array{0: int, 1: string}>> template path => variable name => [line, message] */
    private static array $problems = [];

    /**
     * @param array{0: list<string>, 1: bool}|null $dataIncludes view names the template hands its
     *        whole scope to, and whether one of them could not be resolved; null when the collection
     *        pass was off for this run, which {@see ReadSetResolver} treats as "not proven"
     * @param string|false|null $layout as {@see MarkerPrePass::extendedLayout()}
     */
    public static function register(
        string $viewName,
        int $rootIndex,
        ViewDataContract $contract,
        ?array $dataIncludes = null,
        string|false|null $layout = null,
    ): void {
        $existing = self::$contracts[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$contracts[$viewName] = [$rootIndex, $contract, $dataIncludes, $layout];
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

    /** The layout the view `@extends`, as {@see MarkerPrePass::extendedLayout()} */
    public static function layoutFor(string $viewName): string|false|null
    {
        return self::$contracts[$viewName][3] ?? null;
    }

    /** A composer or creator is registered for the view, so it may be handed data no call site shows. */
    public static function markComposed(string $viewName): void
    {
        self::$composed[$viewName] = true;
    }

    public static function isComposed(string $viewName): bool
    {
        return isset(self::$composed[$viewName]);
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
        self::$composed = [];
    }
}
