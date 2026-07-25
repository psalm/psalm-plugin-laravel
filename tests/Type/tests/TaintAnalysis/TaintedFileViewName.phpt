--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * A user-controlled view *name* resolves to an arbitrary blade path, so it carries the
 * same file + include sinks as `Factory::file()` (see TaintedIncludeViewFactory.phpt).
 * View *data* remains deliberately un-sunk — Blade escapes `{{ }}` (see
 * SafeViewFactoryUserInput.phpt).
 *
 * Both call shapes are pinned: the `view()` helper and the `View` facade static.
 */

function viewHelperNameIsTainted(Request $request): void
{
    view((string) $request->input('template'));
}

function viewFacadeStaticNameIsTainted(Request $request): void
{
    View::make((string) $request->input('template'));
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedInclude on line %d: Detected tainted code passed to include or similar
