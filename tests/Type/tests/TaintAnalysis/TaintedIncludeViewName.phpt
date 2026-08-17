--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * A user-controlled view *name* selects which blade template gets compiled and executed,
 * but FileViewFinder normalizes it before joining a configured view path, so it cannot
 * escape to an arbitrary file the way `Factory::file()`'s path argument can (see
 * TaintedIncludeViewFactory.phpt) — include only, no TaintedFile. View *data* remains
 * deliberately un-sunk — Blade escapes `{{ }}` (see SafeViewFactoryUserInput.phpt).
 *
 * Three call shapes, each with its own sink source. Verified by deleting each in turn:
 * every one is load-bearing for exactly one case, and none covers another.
 *   1. `view($name)` — the sink on the `view()` function itself, in
 *      `stubs/common/Foundation/helpers.phpstub`. It never reaches `Factory::make()`.
 *   2. `View::make($name)` — `stubs/common/View/Factory.phpstub`, copied onto the
 *      facade's `@method` pseudo-method by FacadeTaintForwardingHandler.
 *   3. contract-typed receiver — `stubs/common/Contracts/View/Factory.phpstub`.
 *
 * Case 3 must be reached by DI, not by the zero-argument helper. MissingViewHandler
 * narrows `view()` to the app's resolved *concrete* factory, and it is a return-type
 * provider only (no taint involvement), so `view()->make(...)` would assert nothing
 * about the contract stub and would still pass with that stub deleted.
 */

function viewHelperNameIsTainted(Request $request): void
{
    view((string) $request->input('template'));
}

function viewFacadeStaticNameIsTainted(Request $request): void
{
    View::make((string) $request->input('template'));
}

function viewContractNameIsTainted(Request $request, ViewFactoryContract $factory): void
{
    $factory->make((string) $request->input('template'));
}
?>
--EXPECTF--
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
