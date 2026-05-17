<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Immutable result of {@see Diagnostics::collect()}.
 *
 * Flat shape on purpose — three short groups (versions, boot, failures) read
 * better as direct properties than nested value objects for a CLI report.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class Report
{
    /**
     * @param 'bootstrap'|'testbench_fallback'|null $bootMode
     * @param 'runtime'|'config.platform.php' $phpAnalysisSource
     * @param list<string> $bootstrapErrors
     * @param list<string> $hardFailures
     */
    public function __construct(
        public ?string $pluginVersion,
        public ?string $laravelVersion,
        public ?string $psalmVersion,
        public string $phpRuntimeVersion,
        public ?string $phpRequiredVersion,
        public string $phpAnalysisVersion,
        public string $phpAnalysisSource,
        public ?string $bootMode,
        public ?string $bootPath,
        public array $bootstrapErrors,
        public array $hardFailures,
    ) {}
}
