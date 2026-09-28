--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// Factory::renderWhen()/renderUnless() — the view name is at position 1, not 0.
\Illuminate\Support\Facades\View::renderWhen(true, 'render-when-missing');
\Illuminate\Support\Facades\View::renderWhen(true, 'welcome');
\Illuminate\Support\Facades\View::renderUnless(false, 'render-unless-missing');
\Illuminate\Support\Facades\View::renderUnless(false, 'welcome');

// Factory::renderEach() — a view name at position 0 (rendered per data item) AND
// an "empty" view at position 3 (rendered when $data is empty).
function _diRenderEach(\Illuminate\View\Factory $factory): void {
    // Missing main view, no $empty supplied — only one issue.
    $factory->renderEach('render-each-missing', [], 'item');

    // Main view exists, $empty missing — only one issue, for $empty.
    $factory->renderEach('welcome', [], 'item', 'render-each-empty-missing');

    // Both missing — two issues.
    $factory->renderEach('render-each-both-missing', [], 'item', 'render-each-both-empty-missing');

    // $empty starting with 'raw|' is raw text, not a view name — must not emit.
    $factory->renderEach('welcome', [], 'item', 'raw|Nothing to show');

    // Both resolve — no issues.
    $factory->renderEach('welcome', [], 'item', 'errors.503');
}
?>
--EXPECTF--
MissingView on line %d: View 'render-when-missing' not found in any of the registered view paths
MissingView on line %d: View 'render-unless-missing' not found in any of the registered view paths
MissingView on line %d: View 'render-each-missing' not found in any of the registered view paths
MissingView on line %d: View 'render-each-empty-missing' not found in any of the registered view paths
MissingView on line %d: View 'render-each-both-empty-missing' not found in any of the registered view paths
MissingView on line %d: View 'render-each-both-missing' not found in any of the registered view paths
