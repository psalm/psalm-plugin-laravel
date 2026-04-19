<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\DiagnosticService;
use Illuminate\Support\Facades\Facade;

/**
 * Car repair shop domain: a facade that exposes diagnostic reports for vehicles.
 * The accessor is a class-string, so Laravel's container auto-wires an instance
 * through reflection (no user service provider needed). This drives the
 * `getFacadeRoot()` runtime probe in {@see \Psalm\LaravelPlugin\Handlers\Facades\FacadeMethodHandler}.
 *
 * @method static bool isCritical()
 * @method static bool isMinor()
 */
class Diagnostic extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return DiagnosticService::class;
    }
}
