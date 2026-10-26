<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;
use Psalm\Config;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Issue\DuplicateClass;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\BladeCompileError;

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
     */
    #[\Override]
    public function registerShadowsForAnalysis(array $shadowPaths): void
    {
        $this->projectAnalyzer->getCodebase()->addFilesToAnalyze(\array_combine($shadowPaths, $shadowPaths));
    }

    /** @inheritDoc */
    #[\Override]
    public function queueClassLikesForScanning(array $classNames): void
    {
        $codebase = $this->projectAnalyzer->getCodebase();

        foreach ($classNames as $className) {
            // store_failure=false: every name reaching here is queued speculatively — a compiled
            // component reference on every run, or a bare string literal that most often names no
            // class at all — never because project code proved the class exists. An unresolvable
            // name must therefore not be recorded as missing.
            $codebase->queueClassLikeForScanning($className, false, false);
        }
    }

    /** @inheritDoc */
    #[\Override]
    public function queueResolvableClassLikesForScanning(array $candidates): void
    {
        $config = $this->projectAnalyzer->getCodebase()->config;

        $this->queueClassLikesForScanning(\array_values(\array_filter(
            $candidates,
            fn(string $candidate): bool => $this->isIndependentlyResolvable($config, $candidate),
        )));
    }

    /** @inheritDoc */
    #[\Override]
    public function queueFilesForScanning(array $paths): void
    {
        $scanner = $this->projectAnalyzer->getCodebase()->scanner;

        foreach ($paths as $path) {
            if (!\is_file($path)) {
                continue;
            }

            $scanner->addFileToDeepScan($path);
        }
    }

    #[\Override]
    public function reportCompileFailure(string $templatePath, string $source, string $message): void
    {
        $name = $this->projectAnalyzer->getCodebase()->config->shortenFileName($templatePath);
        $location = TemplateLocation::atLine($templatePath, $name, $source, 1)
            ?? new Raw($source, $templatePath, $name, 0, 0);

        // Nothing to suppress from: the template never compiled, so `<issueHandlers>` is the only
        // way to silence it.
        IssueBuffer::accepts(
            new BladeCompileError("Template not analyzed: {$message}", $location),
            [],
        );
    }

    /** @inheritDoc */
    #[\Override]
    public function existingClassFile(string $className): ?string
    {
        $composerFile = $this->projectAnalyzer->getCodebase()->config->getComposerFilePathForClassLike($className);

        if ($composerFile !== false) {
            // Composer's loaders hand back `vendor/composer/../../app/Foo.php`, which would reach a message verbatim.
            $resolved = \realpath($composerFile);

            return $resolved === false ? $composerFile : $resolved;
        }

        if (!$this->isDeclaredInProcess($className)) {
            return null;
        }

        /** @var class-string $declared proven by the check above */
        $declared = $className;
        $file = (new \ReflectionClass($declared))->getFileName();

        return $file === false ? null : $file;
    }

    /** @inheritDoc */
    #[\Override]
    public function overrideShadowContents(string $shadowPath, string $contents): void
    {
        $this->projectAnalyzer->getCodebase()->addTemporaryFileChanges($shadowPath, $contents);
    }

    /** @inheritDoc */
    #[\Override]
    public function reportClassCollision(
        string $templatePath,
        string $source,
        int $line,
        string $className,
        string $firstFile,
        array $suppressed,
    ): void {
        $config = $this->projectAnalyzer->getCodebase()->config;
        $name = $config->shortenFileName($templatePath);
        $location = TemplateLocation::atLine($templatePath, $name, $source, $line)
            ?? new Raw($source, $templatePath, $name, 0, 0);

        IssueBuffer::accepts(
            new DuplicateClass('Class ' . $className . ' has already been defined in ' . $config->shortenFileName($firstFile), $location),
            $suppressed,
        );
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
        return $config->getComposerFilePathForClassLike($candidate) !== false || $this->isDeclaredInProcess($candidate);
    }

    private function isDeclaredInProcess(string $className): bool
    {
        return \class_exists($className, false)
            || \interface_exists($className, false)
            || \trait_exists($className, false)
            || \enum_exists($className, false);
    }
}
