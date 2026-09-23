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

    /** @var list<string> */
    public array $queuedFiles = [];

    public int $markCalls = 0;

    public function __construct(private readonly bool $markSucceeds = true) {}

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

    #[\Override]
    public function queueFilesForScanning(array $paths): void
    {
        $this->queuedFiles = $paths;
    }
}
