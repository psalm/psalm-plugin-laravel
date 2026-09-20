<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Config;
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
    public function __construct(private readonly ProjectAnalyzer $projectAnalyzer) {}

    /** @inheritDoc */
    #[\Override]
    public function markTemplatesReportable(array $templatePaths): bool
    {
        return ProjectFileInjector::inject($this->projectAnalyzer, $templatePaths);
    }

    /** @inheritDoc */
    #[\Override]
    public function registerShadowsForAnalysis(array $shadowPaths): void
    {
        $files = [];

        foreach ($shadowPaths as $shadowPath) {
            $files[$shadowPath] = $shadowPath;
        }

        $this->projectAnalyzer->getCodebase()->addFilesToAnalyze($files);
    }

    /** @inheritDoc */
    #[\Override]
    public function queueClassLikesForScanning(array $classNames): void
    {
        $codebase = $this->projectAnalyzer->getCodebase();

        foreach ($classNames as $className) {
            // store_failure=false: these are queued speculatively on every run, not because project
            // code proved the class exists, so an unresolvable name must not be recorded as missing.
            $codebase->queueClassLikeForScanning($className, false, false);
        }
    }

    /** @inheritDoc */
    #[\Override]
    public function queueResolvableClassLikesForScanning(array $candidates): void
    {
        $codebase = $this->projectAnalyzer->getCodebase();
        $config = $codebase->config;

        foreach ($candidates as $candidate) {
            if (!$this->isIndependentlyResolvable($config, $candidate)) {
                continue;
            }

            // store_failure=false: a string literal is speculative by nature (most name no class at
            // all), so a name that turns out unresolvable after all must never be recorded as
            // missing — see queueClassLikesForScanning() above for the same rationale.
            $codebase->queueClassLikeForScanning($candidate, false, false);
        }
    }

    /**
     * Whether Psalm could resolve `$candidate` to a file WITHOUT this method's own queueing being
     * the reason it can. Neither arm below triggers autoloading (`class_exists()` etc. are always
     * called with $autoload=false), so this mirrors what Psalm's scanner will do next rather than
     * forcing a resolution that would not otherwise happen.
     *
     * The composer arm alone is not enough: `Config::$composer_class_loader` is nullable (a psalm.phar
     * run with no project autoloader, or this plugin's own test fixtures, which stub `vendor/autoload.php`
     * as a no-op), so the class_exists()-family arm is load-bearing, not belt-and-braces, for any
     * class a real bootstrap already declared in-process.
     */
    private function isIndependentlyResolvable(Config $config, string $candidate): bool
    {
        if ($config->getComposerFilePathForClassLike($candidate) !== false) {
            return true;
        }

        return \class_exists($candidate, false)
            || \interface_exists($candidate, false)
            || \trait_exists($candidate, false)
            || \enum_exists($candidate, false);
    }
}
