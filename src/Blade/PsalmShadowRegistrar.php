<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Internal\Analyzer\ProjectAnalyzer;

/**
 * Registers Blade shadows with the running Psalm analysis.
 *
 * The split is deliberate and asymmetric:
 *
 *  - the SHADOW is scanned and analyzed, and must stay unreportable. `Codebase::addFilesToAnalyze()`
 *    covers both halves (deep scan + analyze). Psalm's `addFilesToShowResults()` is not called for
 *    it: `Analyzer::addFilesToAnalyze()` already writes the same `files_with_analysis_results` map,
 *    and making the shadow reportable in `ProjectAnalyzer` would make `TaintFlowGraph` skip every
 *    flow whose source sits in it.
 *  - the TEMPLATE is reportable but never analyzed: it is not PHP. Only the project-file write
 *    applies to it, which is what lets a later remap put an issue on the `.blade.php` path.
 *
 * @internal
 */
final class PsalmShadowRegistrar implements ShadowRegistrar
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(private readonly ProjectAnalyzer $projectAnalyzer) {}

    /** @inheritDoc */
    #[\Override]
    public function markTemplatesReportable(array $templatePaths): bool
    {
        return ProjectFileInjector::inject($this->projectAnalyzer, $templatePaths);
    }

    /**
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public function registerShadowsForAnalysis(array $shadowPaths): void
    {
        $files = [];

        foreach ($shadowPaths as $shadowPath) {
            $files[$shadowPath] = $shadowPath;
        }

        $this->projectAnalyzer->getCodebase()->addFilesToAnalyze($files);
    }
}
