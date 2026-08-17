--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeaderKey(\Illuminate\Http\Request $request) {
    $key = $request->input('header_name');
    return response('OK')->header($key, 'static-value');
}
?>
--EXPECTF--
MissingReturnType on line %d: Method setHeaderKey does not have a return type, expecting Illuminate\Http\Response&static
MixedAssignment on line %d: Unable to determine the type that $key is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Http\Response::header cannot be mixed, expecting string
TaintedHeader on line %d: Detected tainted header
