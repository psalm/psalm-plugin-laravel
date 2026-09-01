--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// Factory::first()/View::first() — `Arr::first($views, fn => exists($view))` throws
// only when NONE of the candidates exist, so the diagnostic only fires when every
// literal candidate is missing.

// All literal, all missing — should emit ONE issue naming both candidates.
\Illuminate\Support\Facades\View::first(['first-missing-a', 'first-missing-b']);

// One candidate exists — should not emit.
\Illuminate\Support\Facades\View::first(['welcome', 'first-missing-c']);

// A dynamic (non-literal) candidate — bail entirely, should not emit.
function _diFactoryDynamic(\Illuminate\View\Factory $factory, string $dynamicView): void {
    $factory->first([$dynamicView, 'first-missing-d']);
}

// A namespaced candidate — resolves through paths we don't track, bail entirely
// even though the other literal candidate is missing.
\Illuminate\Support\Facades\View::first(['mail::html.header', 'first-missing-e']);

// Empty candidate list — bail, nothing to check.
\Illuminate\Support\Facades\View::first([]);
?>
--EXPECTF--
MissingView on line %d: None of the views 'first-missing-a', 'first-missing-b' were found in any of the registered view paths
