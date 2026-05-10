<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\Progress\Progress;

/**
 * Discovers `.phpstub` files for the plugin's stub registration.
 *
 * Splits responsibility from {@see \Psalm\LaravelPlugin\Plugin}: filesystem
 * traversal and version-directory selection are pure helpers with no Psalm
 * coupling, so they live next to the other utilities and can be exercised
 * by unit tests without booting the plugin.
 *
 * @internal
 */
final class StubFileFinder
{
    /**
     * Recursively find all `.phpstub` files in a directory.
     *
     * Results are sorted to ensure deterministic stub registration order.
     * RecursiveDirectoryIterator returns files in filesystem order, which
     * varies across OSes (alphabetical on APFS/HFS+, inode order on ext4).
     *
     * Stub loading order matters: when multiple stubs declare the same method
     * on the same class, Psalm reuses the MethodStorage and re-applies docblock
     * parsing. Type annotations (`@return`, `@param`) use `=` so the last-loaded
     * stub wins; taint annotations (`@psalm-taint-*`) use `|=` and accumulate.
     * Without sorting, moving or renaming stub files can silently change types.
     * See docs/contributing/README.md "Stub merging" for details.
     *
     * @return list<string>
     */
    public static function findIn(string $directory, Progress $output): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $stubs = [];

        try {
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'phpstub') {
                    continue;
                }

                $realPath = $file->getRealPath();

                if (!\is_string($realPath)) {
                    continue;
                }

                $stubs[] = $realPath;
            }
        } catch (\UnexpectedValueException $unexpectedValueException) {
            // RecursiveIteratorIterator can throw during iteration on unreadable subdirectories.
            // Return whatever stubs were collected before the error — partial results from
            // readable subdirectories are better than none.
            $output->warning("Laravel plugin: error scanning stub directory '{$directory}': {$unexpectedValueException->getMessage()}");
        }

        \sort($stubs);

        return $stubs;
    }

    /** @return list<string> */
    public static function commonStubs(string $stubsRoot, Progress $output): array
    {
        return self::findIn($stubsRoot . '/common', $output);
    }

    /**
     * Collect stubs from all version directories that are <= the installed Laravel version.
     *
     * Supports both major-only directories (e.g. "12/", "13/") and patch-level directories
     * (e.g. "12.20.0/", "12.42.0/"). Directories are sorted in ascending version order so
     * that later versions override earlier ones for same-named stubs.
     *
     * @see https://www.php.net/version_compare — treats "12" as "12.0.0"
     *
     * @return list<string>
     */
    public static function stubsForLaravelVersion(string $stubsRoot, string $version, Progress $output): array
    {
        // Collect version directories (names starting with a digit, e.g. "12", "12.20.0", "13")
        $candidates = [];

        foreach (new \DirectoryIterator($stubsRoot) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $dirName = $entry->getFilename();

            // Skip non-version directories (e.g. "common")
            if (\ctype_digit($dirName[0])) {
                $candidates[] = $dirName;
            }
        }

        $stubs = [];

        foreach (self::filterVersionDirectories($candidates, $version) as $dir) {
            \array_push($stubs, ...self::findIn($stubsRoot . '/' . $dir, $output));
        }

        return $stubs;
    }

    /**
     * Filter and sort version directory names, keeping only those <= the target version.
     *
     * @param list<string> $candidates directory names (e.g. ["13", "12", "12.20.0"])
     *
     * @return list<string> sorted ascending by version (e.g. ["12", "12.20.0"])
     *
     * @psalm-pure
     *
     * @internal used by tests
     */
    public static function filterVersionDirectories(array $candidates, string $targetVersion): array
    {
        $matched = \array_filter(
            $candidates,
            static fn(string $dir): bool => \version_compare($dir, $targetVersion, '<='),
        );

        \usort($matched, static fn(string $a, string $b): int => \version_compare($a, $b));

        return $matched;
    }
}
