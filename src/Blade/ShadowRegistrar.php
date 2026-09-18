<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The two registrations a compiled template needs from Psalm, kept behind an interface because the
 * real implementation needs a live `ProjectAnalyzer` that a unit test cannot build.
 */
interface ShadowRegistrar
{
    /**
     * Makes issues located in these Blade templates reportable.
     *
     * @param list<string> $templatePaths `.blade.php` paths, never shadow paths
     *
     * @return bool false when Psalm's project-file list could not be extended; the caller must then
     *              register no shadows at all
     */
    public function markTemplatesReportable(array $templatePaths): bool;

    /** @param list<string> $shadowPaths compiled PHP files, never `.blade.php` paths */
    public function registerShadowsForAnalysis(array $shadowPaths): void;
}
