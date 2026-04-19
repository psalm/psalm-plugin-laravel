--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Facades\BrokenSeeFacade;
use App\Facades\Diagnostic;

/**
 * Car repair shop domain, modelled after the koel repro of
 * https://github.com/psalm/psalm-plugin-laravel/issues/787:
 * `getReport` is NOT in the facade's `@method` catalogue but IS a public method on
 * the `@see`-referenced `App\Services\DiagnosticService`. Resolver path 3 wins.
 */
function test_see_resolves_method_not_in_method_catalogue(): string
{
    /** @psalm-check-type-exact $report = string */
    $report = Diagnostic::getReport(checkCache: false);

    return $report;
}

/**
 * `@method` takes precedence over `@see`. The facade declares `@method static bool isCritical()`
 * but `DiagnosticService::isCritical()` returns `string` at runtime — the facade's declaration
 * wins because FacadeMethodHandler explicitly short-circuits when `pseudo_static_methods`
 * contains the method (without the short-circuit, our return_type_provider would fire before
 * `checkPseudoMethod` at AtomicStaticCallAnalyzer.php:639 and override the @method return).
 */
function test_method_annotation_wins_over_see(): bool
{
    /** @psalm-check-type-exact $critical = bool */
    $critical = Diagnostic::isCritical();

    return $critical;
}

/**
 * Non-public methods on the underlying class must NOT be surfaced on the facade.
 * `DiagnosticService::internalCheck()` is protected, so `Diagnostic::internalCheck()` should
 * still emit UndefinedMagicMethod — mirroring runtime `__callStatic` behaviour.
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
 * `@see` pointing at a non-existent class must fall through cleanly to UndefinedMagicMethod.
 * Verifies the resolver handles broken user docblocks without throwing and without
 * spuriously resolving methods on an unrelated class.
 */
function test_broken_see_target_falls_through(): void
{
    BrokenSeeFacade::anyMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::internalcheck does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\Diagnostic::definitelynotamethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Facades\BrokenSeeFacade::anymethod does not exist
