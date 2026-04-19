<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Car repair shop domain: a diagnostic report service that inspects a vehicle's
 * state. Test fixture for {@see \App\Facades\Diagnostic}. Methods exercise the
 * paths resolved by {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler}:
 * a public method not listed in the facade's `@method` catalogue (getReport)
 * resolves via the runtime probe, a public method whose signature conflicts with
 * `@method` (isCritical) verifies `@method` precedence, and a protected method
 * (internalCheck) must NOT be surfaced on the facade.
 */
class DiagnosticService
{
    public function getReport(bool $checkCache = true): string
    {
        return $checkCache ? 'cached-report' : 'fresh-report';
    }

    /** Concrete signature conflicts with the facade's `@method static bool isCritical()` on purpose. */
    public function isCritical(): string
    {
        return 'critical';
    }

    protected function internalCheck(): bool
    {
        return true;
    }
}
