--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeader(\Illuminate\Http\Request $request) {
    $value = $request->input('x_custom');
    return response('OK')->header('X-Custom', $value);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method setHeader does not have a return type, expecting Illuminate\Http\Response&static
MixedAssignment on line %d: Unable to determine the type that $value is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Http\Response::header cannot be mixed, expecting array<array-key, mixed>|string
TaintedHeader on line %d: Detected tainted header
