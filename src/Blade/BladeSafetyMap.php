<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use function str_ends_with;

/**
 * Per-template record of which data keys reach a raw echo context.
 *
 * The map is built once during plugin boot (similar to how MissingViewHandler
 * loads view paths) and queried for every `view()` / `Factory::make()` call.
 *
 * Entries are keyed by Blade's dotted view name (`emails.welcome`), matching
 * how the view() helper is called in user code. Dotted names are resolved
 * relative to the template roots supplied at construction.
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
     * @param array<string, list<string>> $unsafeKeysByView
     *   view name => list of top-level variable names referenced in {!! !!} or @php
     */
    public function __construct(
        private array $unsafeKeysByView,
    ) {}

    /**
     * Build a map by scanning every `*.blade.php` file under the given roots.
     *
     * @param list<string> $viewPaths absolute paths of view directories, in the order
     *                                returned by FileViewFinder::getPaths()
     *
     * @psalm-api
     */
    public static function build(array $viewPaths): self
    {
        $map = [];

        foreach ($viewPaths as $root) {
            $root = \rtrim($root, \DIRECTORY_SEPARATOR);

            if (!\is_dir($root)) {
                continue;
            }

            foreach (self::iterateBladeFiles($root) as $file) {
                // getPathname() preserves the `$root` prefix the iterator was
                // started with; getRealPath() resolves symlinks (e.g. macOS
                // `/var` -> `/private/var`) and would break prefix-stripping.
                $path = $file->getPathname();

                $source = \file_get_contents($path);

                if ($source === false) {
                    continue;
                }

                $viewName = self::viewNameFor($root, $path);
                $unsafe = BladeTemplateScanner::unsafeVariables($source);

                if ($unsafe === []) {
                    continue;
                }

                // First path wins — matches FileViewFinder::findInPaths(),
                // which iterates paths in order and returns the first match.
                if (!isset($map[$viewName])) {
                    $map[$viewName] = $unsafe;
                }
            }
        }

        return new self($map);
    }

    /**
     * @return list<string> keys whose values must be treated as html sinks
     *                      when rendered through this view, empty for safe views
     *
     * @psalm-api
     */
    public function unsafeKeysFor(string $viewName): array
    {
        return $this->unsafeKeysByView[$viewName] ?? [];
    }

    /** @psalm-api */
    public function hasUnsafeKeys(string $viewName): bool
    {
        return isset($this->unsafeKeysByView[$viewName]);
    }

    /**
     * @return list<string>
     *
     * @psalm-api
     */
    public function knownViews(): array
    {
        return \array_keys($this->unsafeKeysByView);
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
