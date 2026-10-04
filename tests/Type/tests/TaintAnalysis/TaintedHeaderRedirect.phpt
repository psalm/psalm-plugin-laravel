--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function loginRedirect(\Illuminate\Http\Request $request): void {
    $returnUrl = $request->input('return_url');
    redirect($returnUrl);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $returnUrl is being assigned to
MixedArgument on line %d: Argument 1 of redirect cannot be mixed, expecting null|string
TaintedHeader on line %d: Detected tainted header
