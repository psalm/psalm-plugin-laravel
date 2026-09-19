<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Shadow path => template facts, populated at boot and read during analysis by
 * {@see BladeIssueRemapHandler}.
 *
 * Static because the reader is a Psalm event handler, which Psalm instantiates itself and hands
 * nothing but the event. Populated before Psalm forks its analysis workers, so every worker
 * inherits a complete registry by copy-on-write.
 *
 * @internal
 */
final class ShadowRegistry
{
    /** @var array<string, ShadowEntry> shadow path => entry */
    private static array $entries = [];

    /** @var array<string, string|false> template path => contents, or false when unreadable */
    private static array $sources = [];

    /**
     * @psalm-external-mutation-free
     */
    public static function register(string $shadowPath, ShadowEntry $entry): void
    {
        self::$entries[$shadowPath] = $entry;
    }

    /**
     * Null for any path that is not a registered shadow, which is every normal project file.
     *
     * @psalm-external-mutation-free
     */
    public static function entryFor(string $shadowPath): ?ShadowEntry
    {
        return self::$entries[$shadowPath] ?? null;
    }

    /**
     * Template bytes, read once per run. The remap needs them to turn a line number into the byte
     * offsets a `CodeLocation\Raw` is built from, and to render the snippet a reporter prints.
     */
    public static function templateSource(string $templatePath): ?string
    {
        if (!\array_key_exists($templatePath, self::$sources)) {
            self::$sources[$templatePath] = @\file_get_contents($templatePath);
        }

        $source = self::$sources[$templatePath];

        return $source === false ? null : $source;
    }

    /**
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$entries = [];
        self::$sources = [];
    }
}
