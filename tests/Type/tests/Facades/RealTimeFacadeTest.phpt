--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Facades\App\Services\DiagnosticService;
use Facades\App\Services\NoSuchService;

/**
 * Laravel real-time facades: importing any class under the `Facades\` prefix
 * (`use Facades\App\Services\DiagnosticService;`) lets it be called statically,
 * forwarding to a resolved `App\Services\DiagnosticService` instance.
 *
 * The plugin needs no real-time-facade-specific code. Booting the app runs
 * `RegisterFacades`, registering `AliasLoader::load` as an autoloader. The facade
 * class lives in no project file; Psalm only learns it exists by reflecting
 * `Facades\App\Services\DiagnosticService`, which triggers the autoloader: the
 * `Facades\` prefix routes to `ensureFacadeExists()`, writing a real `Facade`
 * subclass (`@mixin \App\Services\DiagnosticService`) to the app's storage cache
 * and requiring it. Psalm scans that file; the static call then resolves to the
 * underlying instance method's return type. The generated `@mixin` and the
 * plugin's FacadeMethodHandler (which covers concrete Facade subclasses) both
 * resolve it, so this test guards the autoloader-driven discovery rather than one
 * resolution layer.
 */
function realtime_facade_resolves_to_underlying_return_type(): string
{
    /** @psalm-check-type-exact $report = string */
    $report = DiagnosticService::getReport();

    return $report;
}

/**
 * Named-parameter calls resolve through the underlying service signature: the
 * parameter name (`checkCache`) is recognized for binding.
 */
function realtime_facade_resolves_named_parameter_call(): string
{
    /** @psalm-check-type-exact $report = string */
    $report = DiagnosticService::getReport(checkCache: false);

    return $report;
}

/**
 * A `Facades\` import whose target class does not exist still generates a facade
 * (the generator only string-manipulates the name), but its `@mixin` points at an
 * undefined class and the accessor cannot be auto-wired, so no FacadeMethodHandler
 * registers either. Both resolution layers no-op and the call falls through to a
 * clean UndefinedMagicMethod, no fatal.
 */
function realtime_facade_missing_target_errors_cleanly(): void
{
    NoSuchService::whatever();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Facades\App\Services\NoSuchService::whatever does not exist
