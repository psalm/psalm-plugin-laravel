<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The discoverable templates that Blade analysis publishes for annotation.
 *
 * Static because Psalm instantiates event handlers itself and hands them no plugin state.
 *
 * @internal
 */
final class ViewReferenceRegistry
{
    /** @var array<array-key, array{0: int, 1: string}> view name => [view root index, template path] */
    private static array $templates = [];

    /**
     * A template claims its view name the same way {@see ContractRegistry} does: the lowest root
     * index wins, because that is the file Laravel actually renders for that name.
     */
    public static function registerTemplate(string $viewName, int $rootIndex, string $templatePath): void
    {
        $existing = self::$templates[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$templates[$viewName] = [$rootIndex, $templatePath];
    }

    /**
     * Every view name a discovered template claimed, and the file it claimed it with.
     *
     * A numeric view name comes back as the int PHP casts its array key to.
     *
     * @return array<array-key, string> view name => template path
     */
    public static function templates(): array
    {
        return \array_map(static fn(array $entry): string => $entry[1], self::$templates);
    }


    public static function reset(): void
    {
        self::$templates = [];
    }
}
