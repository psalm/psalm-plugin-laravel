<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Diagnostics;

/**
 * One plugin-initialization diagnostic: a severity, the lifecycle stage that
 * produced it, and a human-readable message.
 *
 * A plain readonly record — {@see DiagnosticsBuffer} collects and renders these.
 * Severity and stage are closed string sets rather than enums, matching the
 * `ApplicationProvider::$bootMode` convention (read-only labels, never compared
 * against typed cases) and keeping the buffer a lightweight log, not a type hierarchy.
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
