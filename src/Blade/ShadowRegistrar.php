<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The two registrations a compiled template needs from Psalm, kept behind an interface because the
 * real implementation needs a live `ProjectAnalyzer` that a unit test cannot build.
 *
 * @psalm-mutable Interface does not require implementations to be immutable.
 */
interface ShadowRegistrar
{
    /**
     * Makes issues located in these Blade templates reportable.
     *
     * Impure by contract: both registrations write into the running analysis.
     *
     * @param list<string> $templatePaths `.blade.php` paths, never shadow paths
     *
     * @return bool false when Psalm's project-file list could not be extended; the caller must then
     *              register no shadows at all
     *
     * @psalm-impure
     */
    public function markTemplatesReportable(array $templatePaths): bool;

    /**
     * @param list<string> $shadowPaths compiled PHP files, never `.blade.php` paths
     *
     * @psalm-impure
     */
    public function registerShadowsForAnalysis(array $shadowPaths): void;
}
