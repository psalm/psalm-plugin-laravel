--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * `ResponseFactory::view()` forwards its view name to `Factory::make()` (or `first()` for
 * the array form), so a user-controlled name selects which blade template executes —
 * include only, it cannot escape to an arbitrary file.
 *
 * Both receivers are pinned: the zero-argument `response()` helper, typed as the
 * `Illuminate\Contracts\Routing\ResponseFactory` contract, and the concrete class. Sinks do
 * not cross the interface boundary, so each one needs its own stub declaration.
 *
 * View *data* stays un-sunk — see SafeResponseFactoryViewUserInput.phpt.
 */

function responseHelperViewNameIsTainted(Request $request): void
{
    response()->view((string) $request->input('template'));
}

function responseConcreteViewNameIsTainted(Request $request, ResponseFactory $response): void
{
    $response->view((string) $request->input('template'));
}
?>
--EXPECTF--
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
