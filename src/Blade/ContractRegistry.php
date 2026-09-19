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
    /** @var array<string, array{0: int, 1: ViewDataContract}> view name => [view root index, contract] */
    private static array $contracts = [];

    public static function register(string $viewName, int $rootIndex, ViewDataContract $contract): void
    {
        $existing = self::$contracts[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$contracts[$viewName] = [$rootIndex, $contract];
    }

    /** Null for a view name no template declared — which is every view outside the compiled set. */
    public static function contractFor(string $viewName): ?ViewDataContract
    {
        return self::$contracts[$viewName][1] ?? null;
    }

    public static function reset(): void
    {
        self::$contracts = [];
    }
}
