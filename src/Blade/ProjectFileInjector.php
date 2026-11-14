<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Adds paths to `ProjectAnalyzer::$project_files` after the fact.
 *
 * That list is what `ProjectAnalyzer::canReportIssues()` reads, and `Config::reportIssueInFile()`
 * consults it, so a file the plugin generates can never have issues reported for it without this
 * write. Psalm builds the list inside `check()` / `checkPaths()` (`initProjectFiles()`), the property
 * is private, and Psalm 7.5 has no public setter, hence reflection. A public API (vimeo/psalm#13718,
 * contract: call before scanning starts) replaces this class, and with it the scan-time
 * {@see TemplateReportability::apply()} call in the issue remap.
 *
 * @todo-by https://github.com/vimeo/psalm/issues/13718 delete this class and ProjectFileInjectorTest, call the new Psalm API from the boot instead, and raise the Psalm floor in composer.json to the release that ships it
 *
 * WHEN the write may happen is the caller's problem: see {@see TemplateReportability}, which owns
 * that decision. The boot only {@see self::canInject() probes} that the write can work.
 *
 * Every failure mode returns false instead of throwing, so a Psalm internal change costs the Blade
 * feature rather than the whole run. The target is typed `object` on purpose: the guards are the
 * fragile part of this class and stay testable against the shapes a rename could leave behind.
 *
 * @internal
 */
final class ProjectFileInjector
{
    private const PROPERTY = 'project_files';

    /**
     * Whether {@see self::inject()} could write to this target. Writes the current value back: a
     * readonly or otherwise unwritable property passes every read guard and would only fail later,
     * when nothing can degrade any more.
     */
    public static function canInject(object $projectAnalyzer): bool
    {
        $read = self::read($projectAnalyzer);

        return $read !== null && self::write($projectAnalyzer, $read[0], $read[1]);
    }

    /**
     * @param list<string> $filePaths absolute paths
     *
     * @return bool false when the write did not happen
     */
    public static function inject(object $projectAnalyzer, array $filePaths): bool
    {
        if ($filePaths === []) {
            return true;
        }

        $read = self::read($projectAnalyzer);

        if ($read === null) {
            return false;
        }

        [$property, $projectFiles] = $read;

        foreach ($filePaths as $filePath) {
            $projectFiles[$filePath] = $filePath;
        }

        return self::write($projectAnalyzer, $property, $projectFiles);
    }

    /**
     * @param array<array-key, mixed> $projectFiles
     */
    private static function write(object $projectAnalyzer, \ReflectionProperty $property, array $projectFiles): bool
    {
        try {
            $property->setValue($projectAnalyzer, $projectFiles);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: \ReflectionProperty, 1: array<array-key, mixed>}|null
     */
    private static function read(object $projectAnalyzer): ?array
    {
        if (!\property_exists($projectAnalyzer, self::PROPERTY)) {
            return null;
        }

        try {
            // No setAccessible(): a no-op since PHP 8.1, and calling it raises a deprecation that
            // Psalm's error handler promotes to an exception.
            $property = new \ReflectionProperty($projectAnalyzer, self::PROPERTY);

            if (!$property->isInitialized($projectAnalyzer)) {
                return null;
            }

            $projectFiles = self::asArray($property->getValue($projectAnalyzer));
        } catch (\Throwable) {
            return null;
        }

        return $projectFiles === null ? null : [$property, $projectFiles];
    }

    /**
     * A helper rather than a local, so no `mixed` variable is left behind (type-coverage.md, pattern 6).
     *
     * @return array<array-key, mixed>|null
     *
     * @psalm-pure
     */
    private static function asArray(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }
}
