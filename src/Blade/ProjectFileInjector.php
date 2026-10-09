<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Adds paths to `ProjectAnalyzer::$project_files` after the fact.
 *
 * The project-file list is built from psalm.xml in the `ProjectAnalyzer` constructor, before
 * `Config::initializePlugins()` runs, and `ProjectAnalyzer::canReportIssues()` reads nothing else —
 * so a file the plugin generates at boot can never have issues reported for it without this write.
 * The property is private and there is no API for it on Psalm 6, hence reflection.
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
     * @param list<string> $filePaths absolute paths
     *
     * @return bool false when the write did not happen
     */
    public static function inject(object $projectAnalyzer, array $filePaths): bool
    {
        if ($filePaths === []) {
            return true;
        }

        if (!\property_exists($projectAnalyzer, self::PROPERTY)) {
            return false;
        }

        try {
            // No setAccessible(): a no-op since PHP 8.1, and calling it raises a deprecation that
            // Psalm's error handler promotes to an exception.
            $property = new \ReflectionProperty($projectAnalyzer, self::PROPERTY);

            if (!$property->isInitialized($projectAnalyzer)) {
                return false;
            }

            $projectFiles = $property->getValue($projectAnalyzer);

            if (!\is_array($projectFiles)) {
                return false;
            }

            foreach ($filePaths as $filePath) {
                $projectFiles[$filePath] = $filePath;
            }

            $property->setValue($projectAnalyzer, $projectFiles);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
