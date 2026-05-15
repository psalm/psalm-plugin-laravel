<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Per-template safety record for every Blade view discovered under the
 * configured view roots.
 *
 * The map is built once during plugin boot (similar to how MissingViewHandler
 * loads view paths) and queried for every `view()` / `Factory::make()` call.
 *
 * Entries are keyed by Blade's dotted view name (`emails.welcome`), matching
 * how the view() helper is called in user code. Dotted names are resolved
 * relative to the template roots supplied at construction.
 *
 * Every resolved Blade view is recorded — SAFE, UNSAFE_KEYS, or UNKNOWN.
 * Earlier versions only recorded views with unsafe keys; that conflated "we
 * scanned this view and it is safe" with "we never saw this view", which
 * silently downgrades UNKNOWN to SAFE at the handler layer. Recording all
 * three states is required for sound taint refinement.
 *
 * First-match-wins matches Laravel's {@see \Illuminate\View\FileViewFinder}:
 * the finder iterates view paths in order and returns the first existing
 * `.blade.php`. The map mirrors that, *including* when the first view is SAFE
 * and a later view in the override path is unsafe — earlier versions skipped
 * safe templates, which meant a later UNSAFE view would shadow the SAFE one
 * the finder would actually load.
 *
 * Not marked `@psalm-immutable`: {@see build()} performs filesystem IO, which
 * is inherently impure. Instances returned by `build()` are, however, safe to
 * treat as immutable value objects.
 *
 * @psalm-api
 */
final readonly class BladeSafetyMap
{
    /**
     * @param array<non-empty-string, BladeViewSafety> $safetyByView
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private array $safetyByView,
    ) {}

    /**
     * Build a map by scanning every `*.blade.php` file under the given roots.
     *
     * A single {@see BladeTemplateScanner} is constructed once per build and
     * reused for every template, so the underlying {@see PsalmBladeCompiler}
     * and {@see \PhpParser\Parser} instances are amortised across the scan.
     * Tests that need to substitute a scanner can pass it in.
     *
     * @param list<string>             $viewPaths absolute paths of view directories, in the order
     *                                            returned by FileViewFinder::getPaths()
     * @param BladeTemplateScanner|null $scanner  optional scanner instance; default builds one
     *                                            with {@see BladeTemplateScanner::withDefaults()}.
     *
     * @psalm-api
     */
    public static function build(array $viewPaths, ?BladeTemplateScanner $scanner = null): self
    {
        $scanner ??= BladeTemplateScanner::withDefaults();
        $map = [];

        foreach ($viewPaths as $root) {
            $root = \rtrim($root, \DIRECTORY_SEPARATOR);

            /*
             * Empty-string check must precede is_dir(): is_dir('') emits a
             * "Filename cannot be empty" warning under PHP 8+, which the
             * plugin's error handler turns into a thrown RuntimeException
             * during boot.
             */
            if ($root === '' || !\is_dir($root)) {
                continue;
            }

            foreach (self::iterateBladeFiles($root) as $file) {
                // getPathname() preserves the `$root` prefix the iterator was
                // started with; getRealPath() resolves symlinks (e.g. macOS
                // `/var` -> `/private/var`) and would break prefix-stripping.
                $path = $file->getPathname();

                if ($path === '') {
                    continue;
                }

                $viewName = self::viewNameFor($root, $path);

                if ($viewName === '') {
                    continue;
                }

                // First match wins — matches FileViewFinder::findInPaths(),
                // which iterates paths in order and returns the first match.
                // CRITICAL: this branch must run regardless of the analysis
                // kind; skipping safe views here would let a later-root unsafe
                // shadow take precedence over a first-root safe view that
                // Laravel would actually render.
                if (isset($map[$viewName])) {
                    continue;
                }

                // `@` suppresses the "Permission denied" warning when the
                // file becomes unreadable between the iterator's isFile()
                // check and this call. The `=== false` branch converts the
                // failure to UNKNOWN (FILE_UNREADABLE) so the data is
                // recorded; PR-4+ (handler integration) will surface that
                // state as an explicit Psalm issue or fallback policy
                // decision. Until then there is no user-visible signal
                // for an unreadable view — acceptable because the scanner
                // is not yet wired into analysis output.
                $source = @\file_get_contents($path);

                $analysis = $source === false
                    ? BladeTemplateAnalysis::unknown([BladeUncertaintyReason::FileUnreadable])
                    : $scanner->analyze($source);

                $map[$viewName] = new BladeViewSafety($viewName, $path, $analysis);
            }
        }

        return new self($map);
    }

    /**
     * The full safety record for a view, or null when the view is unknown to
     * the map (e.g. dynamic include, package view we did not scan, or a typo).
     * Callers needing to distinguish "scanned and safe" from "never seen"
     * must use this, not {@see unsafeKeysFor()}.
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function safetyFor(string $viewName): ?BladeViewSafety
    {
        return $this->safetyByView[$viewName] ?? null;
    }

    /**
     * Convenience for handlers that only need the unsafe-key list. Returns
     * the empty list both for SAFE views and for views the map never saw, so
     * it must NOT be used to decide whether to apply the UNKNOWN fallback —
     * use {@see isUnknown()} or {@see safetyFor()} for that decision.
     *
     * @return list<non-empty-string>
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function unsafeKeysFor(string $viewName): array
    {
        return ($this->safetyByView[$viewName] ?? null)?->unsafeKeys() ?? [];
    }

    /**
     * @psalm-api
     * @psalm-mutation-free
     */
    public function isKnownSafe(string $viewName): bool
    {
        return ($this->safetyByView[$viewName] ?? null)?->kind() === BladeViewSafetyKind::Safe;
    }

    /**
     * @psalm-api
     * @psalm-mutation-free
     */
    public function isUnknown(string $viewName): bool
    {
        return ($this->safetyByView[$viewName] ?? null)?->kind() === BladeViewSafetyKind::Unknown;
    }

    /**
     * Every view name the map recorded, in insertion order (first-root first).
     *
     * @return list<non-empty-string>
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function knownViews(): array
    {
        return \array_keys($this->safetyByView);
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private static function iterateBladeFiles(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Match Laravel's FileViewFinder default extension. str_ends_with
            // (not str_contains) so editor temp files like `foo.blade.php.bak`
            // or `foo.blade.php~` don't leak into the scan.
            if (!\str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Convert an absolute template path into Blade's dotted view name, mirroring
     * FileViewFinder::getPossibleViewFiles() in reverse.
     *
     * Example: root=/app/resources/views, path=/app/resources/views/emails/welcome.blade.php
     *          => "emails.welcome"
     *
     * @psalm-pure
     */
    private static function viewNameFor(string $root, string $path): string
    {
        $relative = \substr($path, \strlen($root) + 1);

        // Blade templates are always `.blade.php`; strip the suffix to get
        // the dotted view name. Non-blade files are filtered out earlier.
        if (\str_ends_with($relative, '.blade.php')) {
            $relative = \substr($relative, 0, -\strlen('.blade.php'));
        }

        return \str_replace(\DIRECTORY_SEPARATOR, '.', $relative);
    }
}
