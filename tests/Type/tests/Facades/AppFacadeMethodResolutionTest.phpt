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
 * `@method` takes precedence over the runtime probe. This is also the negative pin for
 * FacadeStubPrecedenceHandler: an ordinary application facade outside a plugin-stubbed
 * first-party facade hierarchy must keep its own pseudo-method declaration. The facade declares
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

/**
 * A method documented only by a class-level `@method` tag on the resolved root (forwarded
 * through the root's `__call`) resolves with the tag's return type and parameters.
 */
function test_root_pseudo_method_resolves(): array
{
    /** @psalm-check-type-exact $parts = list<string> */
    $parts = Diagnostic::listParts(vehicleId: 1);

    return $parts;
}

function test_root_pseudo_method_checks_params(): void
{
    Diagnostic::listParts('not-an-int');
}

/**
 * The facade's own `@method static bool isMinor()` wins over the root's `@method int isMinor()`.
 */
function test_facade_method_wins_over_root_pseudo_method(): bool
{
    /** @psalm-check-type-exact $minor = bool */
    $minor = Diagnostic::isMinor();

    return $minor;
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::internalcheck does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\UnboundAccessorFacade::anymethod does not exist
InvalidArgument on line %d: Argument 1 of App\Facades\Diagnostic::listparts expects int, but 'not-an-int' provided
