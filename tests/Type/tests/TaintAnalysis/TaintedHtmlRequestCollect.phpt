--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Positive: Request::collect() returns a Collection wrapping tainted input.
 *
 * Regression guard for the stub-location fix (#823): collect() lives on
 * Illuminate\Support\Traits\InteractsWithData in Laravel 11+.
 */
function renderCollectRequestData(\Illuminate\Http\Request $request): void {
    echo json_encode($request->collect('name'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
