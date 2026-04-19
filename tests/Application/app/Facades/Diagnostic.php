<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Car repair shop domain: a facade that exposes diagnostic reports for vehicles.
 * Modelled after the koel case that motivated issue #787 — the service is bound by
 * a user service provider under a string alias that our Testbench app does not
 * execute, so {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider} cannot resolve
 * it at plugin-init time. The `@see` docblock is the resolver's hook.
 *
 * @method static bool isCritical()
 * @method static bool isMinor()
 * @see \App\Services\DiagnosticService
 */
class Diagnostic extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'Diagnostic';
    }
}
