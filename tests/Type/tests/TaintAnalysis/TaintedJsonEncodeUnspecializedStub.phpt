--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-json-encode-unspecialized.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * With an unspecialized json_encode() stub the argument-to-return edge is shared by every call, so the
 * flags of the second call would overwrite the removal of the first and silence its finding. Psalm's own
 * pooling also taints the second call's output, hence the second finding. TaintedHtml is lost on both
 * lines by core's own json_encode() html removal, which does not check specialization.
 */
function render(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, 0);
    echo json_encode('safe', 15);
}
?>
--EXPECTF--
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
