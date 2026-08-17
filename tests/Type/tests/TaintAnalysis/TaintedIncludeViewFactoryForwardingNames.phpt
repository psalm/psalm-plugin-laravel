--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\View\Factory;

/**
 * Every `Factory` method that resolves a view name forwards it to `make()`, so each name
 * param carries the same include sink — no TaintedFile, the finder cannot be steered to an
 * arbitrary file. Verified against Illuminate\View\Factory: `first()` picks from `$views`,
 * `renderWhen()` calls `make()`, `renderUnless()` delegates to `renderWhen()`, and
 * `renderEach()` calls `make($view)` per row.
 *
 * `renderEach()`'s `$empty` is a view name too: the no-rows branch calls `make($empty)`
 * unless the value uses the literal `raw|` form.
 *
 * View *data* stays un-sunk on all of them — see SafeViewFactoryUserInput.phpt, which
 * exercises these same methods with literal names.
 */

function firstNameIsTainted(Request $request, Factory $factory): void
{
    $factory->first([(string) $request->input('template')]);
}

function renderWhenNameIsTainted(Request $request, Factory $factory): void
{
    $factory->renderWhen(true, (string) $request->input('template'));
}

function renderUnlessNameIsTainted(Request $request, Factory $factory): void
{
    $factory->renderUnless(false, (string) $request->input('template'));
}

function renderEachNameIsTainted(Request $request, Factory $factory): void
{
    $factory->renderEach((string) $request->input('template'), [], 'item');
}

function renderEachEmptyViewIsTainted(Request $request, Factory $factory): void
{
    $factory->renderEach('rows.row', [], 'item', (string) $request->input('fallback'));
}
?>
--EXPECTF--
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedInclude on line %d: Detected tainted code passed to include or similar
