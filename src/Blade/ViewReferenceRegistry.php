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
    /**
     * Keyed by `array-key`, not `string`: a numeric view name (`123.blade.php` is legal) is stored
     * under the int PHP casts its key to, and comes back out as one.
     *
     * @var array<array-key, true> view name => referenced
     */
    private static array $references = [];

    private static bool $dynamic = false;

    /** @var array<array-key, array{0: int, 1: string}> view name => [view root index, template path] */
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
    public static function registerTemplate(string $viewName, int $rootIndex, string $templatePath): void
    {
        $existing = self::$templates[$viewName] ?? null;

        if ($existing !== null && $existing[0] <= $rootIndex) {
            return;
        }

        self::$templates[$viewName] = [$rootIndex, $templatePath];
    }

    /**
     * A template can be claimed under more than one name at once — a published override owns both
     * its default-root name and its namespace's qualified name (see {@see ViewName}), both pointing
     * at the same file. A reference through EITHER name means the file is rendered, so "unused" is a
     * property of the template PATH, not of any one of its names; a file with two names is reported
     * once, not once per unreferenced name.
     *
     * The key is an `array-key`, not a `string`: PHP stores a numeric view name (`123.blade.php`)
     * under the int it casts the key to, and no cast can put it back — the caller has to.
     *
     * @return array<array-key, string> view name => template path
     */
    public static function unusedTemplates(): array
    {
        $referencedPaths = [];

        foreach (self::$templates as $viewName => [, $templatePath]) {
            if (isset(self::$references[$viewName])) {
                $referencedPaths[$templatePath] = true;
            }
        }

        /** @var array<string, array{0: int, 1: array-key}> $canonical template path => [root index, view name] */
        $canonical = [];

        foreach (self::$templates as $viewName => [$rootIndex, $templatePath]) {
            if (isset($referencedPaths[$templatePath])) {
                continue;
            }

            $existing = $canonical[$templatePath] ?? null;

            if ($existing !== null && $existing[0] <= $rootIndex) {
                continue;
            }

            $canonical[$templatePath] = [$rootIndex, $viewName];
        }

        $unused = [];

        foreach ($canonical as $templatePath => [, $viewName]) {
            $unused[$viewName] = $templatePath;
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
