<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Psalm\LaravelPlugin\Blade\ShadowRegistrar;

/**
 * Records what the bootstrapper handed to Psalm, and can pretend the project-file write failed.
 * The real adapter needs a live `ProjectAnalyzer`, which a unit test cannot build.
 */
final class RecordingShadowRegistrar implements ShadowRegistrar
{
    /** @var list<string> */
    public array $reportableTemplates = [];

    /** @var list<string> */
    public array $analyzedShadows = [];

    /** @var list<string> */
    public array $queuedClassLikes = [];

    /** @var list<string> */
    public array $queuedResolvableClassLikes = [];

    /** @var list<array{0: string, 1: string, 2: string}> template path, source, message */
    public array $compileFailures = [];

    /** @var array<string, string> shadow path => the contents Psalm was told to read instead */
    public array $overriddenShadows = [];

    /** @var list<array{0: string, 1: int, 2: string, 3: string, 4: list<string>}> template path, line, class, first file, suppressed */
    public array $classCollisions = [];

    public int $markCalls = 0;

    /** @param array<string, string> $existingClassFiles FQCN => the file the project already defines it in */
    public function __construct(private readonly bool $markSucceeds = true, private readonly array $existingClassFiles = []) {}

    #[\Override]
    public function markTemplatesReportable(array $templatePaths): bool
    {
        ++$this->markCalls;

        if (!$this->markSucceeds) {
            return false;
        }

        $this->reportableTemplates = $templatePaths;

        return true;
    }

    #[\Override]
    public function registerShadowsForAnalysis(array $shadowPaths): void
    {
        $this->analyzedShadows = $shadowPaths;
    }

    #[\Override]
    public function queueClassLikesForScanning(array $classNames): void
    {
        $this->queuedClassLikes = $classNames;
    }

    #[\Override]
    public function queueResolvableClassLikesForScanning(array $candidates): void
    {
        $this->queuedResolvableClassLikes = $candidates;
    }

    /**
     * Nothing to record: the call site is `Plugin::initBladeAnalysis()`, not `BladeBootstrapper`,
     * so no test driving this double reaches it. The seam is covered end to end by
     * `BladeTemplatesTest`.
     */
    #[\Override]
    public function queueFilesForScanning(array $paths): void {}

    #[\Override]
    public function reportCompileFailure(string $templatePath, string $source, string $message): void
    {
        $this->compileFailures[] = [$templatePath, $source, $message];
    }

    #[\Override]
    public function existingClassFile(string $className): ?string
    {
        return $this->existingClassFiles[$className] ?? null;
    }

    #[\Override]
    public function overrideShadowContents(string $shadowPath, string $contents): void
    {
        $this->overriddenShadows[$shadowPath] = $contents;
    }

    #[\Override]
    public function reportClassCollision(
        string $templatePath,
        string $source,
        int $line,
        string $className,
        string $firstFile,
        array $suppressed,
    ): void {
        $this->classCollisions[] = [$templatePath, $line, $className, $firstFile, $suppressed];
    }
}
