--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Known limitation: $request->validate() taint is not tracked through variable
 * assignment: validate() is a `@method` pseudo-method with no stub source, so
 * the assigned array carries none. Per "silence over false positives"
 * principle, this is acceptable.
 */
function renderValidated(\Illuminate\Http\Request $request): void {
    $data = $request->validate(['body' => 'required|string']);
    echo $data['body']; // No taint reported — known limitation
}
?>
--EXPECTF--
