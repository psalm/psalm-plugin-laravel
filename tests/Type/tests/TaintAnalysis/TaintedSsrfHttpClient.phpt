--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function fetchEmbed(\Illuminate\Http\Request $request) {
    $embedUrl = $request->input('embed_url');
    $http = new \Illuminate\Http\Client\PendingRequest();
    $http->get($embedUrl);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method fetchEmbed does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $embedUrl is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Http\Client\PendingRequest::get cannot be mixed, expecting string
TaintedSSRF on line %d: Detected tainted network request
