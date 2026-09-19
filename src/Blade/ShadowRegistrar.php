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

    /**
     * Queues classes for scanning that no project file may ever reference in code position: the
     * shadow prelude names them only in stacked one-line `@var` docblocks on a single statement, and
     * PhpParser attaches every stacked docblock to one `Doc` node, so `Node::getDocComment()` — all
     * Psalm's scanner reads to queue docblock classes — returns only the last one. Without this,
     * every ambient class but the last-declared one is invisible to the scanner and reports
     * UndefinedDocblockClass the first time nothing else in the project names it in code.
     *
     * @param list<string> $classNames fully-qualified, `\`-prefixed or not
     */
    public function queueClassLikesForScanning(array $classNames): void;
}
