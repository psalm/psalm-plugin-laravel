--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Positive: Request::except() returns a tainted array of the remaining input.
 *
 * Regression guard for the stub-location fix (#823): except() lives on
 * Illuminate\Support\Traits\InteractsWithData in Laravel 11+.
 */
function renderExceptRequestData(\Illuminate\Http\Request $request): void {
    $data = $request->except(['password']);

    echo $data['name'];
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
