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

    /** @var array<string, string> template path => the marker prefix its shadow was compiled with */
    private static array $markerPrefixes = [];

    public static function register(string $shadowPath, ShadowEntry $entry): void
    {
        self::$entries[$shadowPath] = $entry;
    }

    /**
     * The marker prefix a template's shadow carries, recorded at boot from the same bytes the
     * compile (or the freshness check that skipped it) read.
     *
     * Kept here rather than re-derived at relocation time from {@see self::templateSource()}: the
     * prefix is a salted hash of the template, those bytes are read again later, and a template
     * edited in between yields a different prefix. The marker strip would then match nothing,
     * leaving markers in a snippet no template can contain, and the arity gate would drop the
     * author's own call as compiler-generated.
     *
     * Only the prefix is snapshotted, not the source: one short string per template, against a
     * corpus that reaches five figures of templates in one run.
     */
    public static function registerMarkerPrefix(string $templatePath, string $markerPrefix): void
    {
        self::$markerPrefixes[$templatePath] = $markerPrefix;
    }

    /** Null for a template no shadow was registered for, which the caller resolves for itself. */
    public static function markerPrefixFor(string $templatePath): ?string
    {
        return self::$markerPrefixes[$templatePath] ?? null;
    }

    /** Null for any path that is not a registered shadow, which is every normal project file. */
    public static function entryFor(string $shadowPath): ?ShadowEntry
    {
        return self::$entries[$shadowPath] ?? null;
    }

    /**
     * Every registered shadow path. The one authority on what counts as a shadow, which is what
     * keeps {@see RuntimeHelperVisibility} from widening its injection onto project files.
     *
     * @return list<string>
     */
    public static function shadowPaths(): array
    {
        return \array_keys(self::$entries);
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

    public static function reset(): void
    {
        self::$entries = [];
        self::$sources = [];
        self::$markerPrefixes = [];
    }
}
