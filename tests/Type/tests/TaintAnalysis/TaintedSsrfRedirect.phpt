--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function loginRedirect(\Illuminate\Http\Request $request) {
    $returnUrl = $request->input('return_url');
    redirect($returnUrl);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
TaintedHeader on line %d: Detected tainted header
