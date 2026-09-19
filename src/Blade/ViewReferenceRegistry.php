<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Every discoverable template, versus every view name a reference was found for, read by
 * {@see \Psalm\LaravelPlugin\Handlers\Views\UnusedViewHandler}.
 *
 * Static for the same reason as {@see ContractRegistry}: the reader is a Psalm event handler, which
 * Psalm instantiates itself and hands nothing but the event.
 *
 * @internal
 */
final class ViewReferenceRegistry
{
    /** @var array<string, true> view name => referenced */
    private static array $references = [];

    private static bool $dynamic = false;

    /** @var array<string, array{0: int, 1: string, 2: string|null}> view name => [view root index, template path, shadow path] */
    private static array $templates = [];

    public static function addReference(string $viewName): void
    {
        self::$references[$viewName] = true;
    }

    /** One unresolvable reference anywhere makes the whole enumerated set untrustworthy. */
    public static function markDynamic(): void
    {
        self::$dynamic = true;
    }

    public static function isDynamic(): bool
    {
        return self::$dynamic;
    }

    /**
     * A template claims its view name the same way {@see ContractRegistry} does: the lowest root
     * index wins, because that is the file Laravel actually renders for that name.
     */
    public static function registerTemplate(string $viewName, int $rootIndex, string $templatePath, ?string $shadowPath): void
    {
        $existing = self::$templates[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$templates[$viewName] = [$rootIndex, $templatePath, $shadowPath];
    }

    /** @return array<string, array{0: string, 1: string|null}> view name => [template path, shadow path] */
    public static function unusedTemplates(): array
    {
        $unused = [];

        foreach (self::$templates as $viewName => [, $templatePath, $shadowPath]) {
            if (!isset(self::$references[$viewName])) {
                $unused[$viewName] = [$templatePath, $shadowPath];
            }
        }

        return $unused;
    }

    public static function reset(): void
    {
        self::$references = [];
        self::$dynamic = false;
        self::$templates = [];
    }
}
