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
     * @param 'runtime'|'psalm.xml' $phpAnalysisSource
     * @param list<string> $bootstrapErrors
     * @param list<string> $hardFailures
     * @param list<string> $loadedProviders Service provider class names the booted kernel registered, sorted. Empty when boot failed.
     * @param list<array{key: string, value: string, source: string}> $pluginSettings Effective plugin settings in schema order. Empty when an override or the plugin XML was invalid.
     */
    public function __construct(
        public ?string $pluginVersion,
        public ?string $psalmVersion,
        public ?string $laravelVersion,
        public string $phpRuntimeVersion,
        public string $phpAnalysisVersion,
        public string $phpAnalysisSource,
        public ?string $bootMode,
        public ?string $bootPath,
        public array $bootstrapErrors,
        public array $hardFailures,
        public array $loadedProviders,
        public array $pluginSettings,
    ) {}
}
