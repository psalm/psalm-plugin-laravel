--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Known limitation: $request->validate() taint is not tracked through variable
 * assignment. Same root cause as the FormRequest validated() limitation —
 * the type provider overrides the return type, suppressing taint annotation.
 * Per "silence over false positives" principle, this is acceptable.
 * TODO: if Psalm's MethodCallReturnTypeFetcher is fixed to call taintMethodCallResult()
 * even when a type provider returns a type, this test should expect TaintedHtml.
 */
function renderValidated(\Illuminate\Http\Request $request): void {
    $data = $request->validate(['body' => 'required|string']);
    echo $data['body']; // No taint reported — known limitation
}
?>
--EXPECTF--
