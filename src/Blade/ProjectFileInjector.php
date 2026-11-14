<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Adds paths to `ProjectAnalyzer::$project_files` after the fact.
 *
 * The project-file list is built from psalm.xml in the `ProjectAnalyzer` constructor, and
 * `ProjectAnalyzer::canReportIssues()` (so `Config::reportIssueInFile()`) reads nothing else — so a
 * file the plugin generates can never have issues reported for it without this write. The property
 * is private and there is no API for it on Psalm 6, hence reflection.
 *
 * WHEN the write may happen is the caller's problem, and it matters: Psalm 7.4+ queues the list for
 * scanning and analysis AFTER `Config::initializePlugins()`, so a template written at plugin boot is
 * scanned and analyzed as plain PHP. {@see TemplateReportability} therefore writes it at
 * `AfterCodebasePopulated`, and the boot only {@see self::canInject() probes} that the write can work.
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
     * Whether {@see self::inject()} could write to this target, without writing.
     */
    public static function canInject(object $projectAnalyzer): bool
    {
        return self::read($projectAnalyzer) !== null;
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

            /** @psalm-var mixed $projectFiles */
            $projectFiles = $property->getValue($projectAnalyzer);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($projectFiles) ? [$property, $projectFiles] : null;
    }
}
