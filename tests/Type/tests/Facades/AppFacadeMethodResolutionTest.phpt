--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\Diagnostic;
use App\Facades\UnboundAccessorFacade;

/**
 * The facade's accessor is `DiagnosticService::class`, so the runtime probe
 * (`Facade::getFacadeRoot()` + container auto-wiring) returns a DiagnosticService
 * instance. `getReport` is not in the `@method` catalogue but is public on the
 * resolved class — the runtime-probe path wins.
 */
function test_runtime_probe_resolves_method_not_in_method_catalogue(): string
{
    /** @psalm-check-type-exact $report = string */
    $report = Diagnostic::getReport(checkCache: false);

    return $report;
}

/**
 * `@method` takes precedence over the runtime probe. The facade declares
 * `@method static bool isCritical()` but `DiagnosticService::isCritical()` returns
 * `string` at runtime — the facade's declaration wins because FacadeMethodHandler
 * explicitly short-circuits when `pseudo_static_methods` contains the method.
 * Without the short-circuit, our return_type_provider would fire before
 * `checkPseudoMethod` in AtomicStaticCallAnalyzer and override the @method return.
 */
function test_method_annotation_wins_over_runtime_probe(): bool
{
    /** @psalm-check-type-exact $critical = bool */
    $critical = Diagnostic::isCritical();

    return $critical;
}

/**
 * Non-public methods on the underlying class must NOT be surfaced on the facade.
 * `DiagnosticService::internalCheck()` is protected, so `Diagnostic::internalCheck()`
 * should still emit UndefinedMagicMethod — mirroring runtime `__callStatic` behaviour.
 */
function test_protected_method_is_not_exposed(): void
{
    Diagnostic::internalCheck();
}

/**
 * Methods neither in `@method` nor on the underlying class must still emit
 * UndefinedMagicMethod — the resolver returns `null`, not `false`, to keep
 * Psalm's default fall-through semantics intact.
 */
function test_method_absent_everywhere_still_errors(): void
{
    Diagnostic::definitelyNotAMethod();
}

/**
 * Named-parameter calls go through the same analyzer path as positional calls; the
 * resolver must surface the parameter names from the underlying service's signature
 * so argument checking and named binding work identically for facade call sites.
 */
function test_named_parameter_call_resolves(): string
{
    /** @psalm-check-type-exact $report = string */
    $report = Diagnostic::getReport(checkCache: true);

    return $report;
}

/**
 * When the facade's accessor cannot be resolved (no binding in Testbench), the resolver
 * returns null cleanly and method calls fall through to UndefinedMagicMethod — no fatal,
 * no spurious cross-facade method resolution.
 */
function test_unbound_accessor_falls_through(): void
{
    UnboundAccessorFacade::anyMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::internalcheck does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\UnboundAccessorFacade::anymethod does not exist
