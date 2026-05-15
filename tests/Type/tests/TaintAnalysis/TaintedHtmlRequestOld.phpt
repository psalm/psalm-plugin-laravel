--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Positive: Request::old() returns tainted flash-session input.
 *
 * Regression guard for the stub-location fix (#824): old() lives on
 * Illuminate\Http\Concerns\InteractsWithFlashData, not on
 * Illuminate\Http\Concerns\InteractsWithInput where the stub used to live.
 */
function renderOldRequestData(\Illuminate\Http\Request $request): void {
    echo $request->old('name');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
