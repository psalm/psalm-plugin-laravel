<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Diagnostics;

/**
 * One buffered plugin-initialisation diagnostic: a single severity/stage/message
 * record collected during {@see \Psalm\LaravelPlugin\Plugin::__invoke()} and replayed
 * at a stable output point instead of mid-progress.
 *
 * Severity and stage are plain strings, not enums — matching the existing
 * boot-diagnostics convention on {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider}'s
 * `$bootMode`. The literal-union types below keep them typo-safe without enum ceremony.
 *
 * @psalm-type DiagnosticSeverity = 'info'|'warning'|'error'
 * @psalm-type DiagnosticStage = 'boot'|'schema'|'facades'|'translations'|'views'|'handlers'|'stubs'|'internal'
 *
 * @psalm-immutable
 *
 * @internal
 */
final readonly class Diagnostic
{
    /**
     * @param DiagnosticSeverity $severity
     * @param DiagnosticStage    $stage
     */
    public function __construct(
        public string $severity,
        public string $stage,
        public string $message,
    ) {}
}
