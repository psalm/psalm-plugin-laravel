--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * A user-controlled view *name* resolves to an arbitrary blade path, so it carries the
 * same file + include sinks as `Factory::file()` (see TaintedIncludeViewFactory.phpt).
 * View *data* remains deliberately un-sunk — Blade escapes `{{ }}` (see
 * SafeViewFactoryUserInput.phpt).
 *
 * Three call shapes: the `view()` helper, the `View` facade static, and a
 * contract-typed receiver. Only the third exercises
 * `stubs/common/Contracts/View/Factory.phpstub`; the other two resolve against the
 * concrete `Illuminate\View\Factory` stub.
 *
 * Note the contract case must be reached by DI, not by the zero-argument helper:
 * MissingViewHandler narrows `view()` to the app's resolved *concrete* factory, so
 * `view()->make(...)` would assert nothing about the contract stub and still pass
 * with that stub deleted.
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
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar
