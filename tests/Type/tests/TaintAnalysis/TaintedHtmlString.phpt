--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderBio(\Illuminate\Http\Request $request) {
    $bio = $request->input('bio');

    return new \Illuminate\Support\HtmlString($bio);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method renderBio does not have a return type, expecting Illuminate\Support\HtmlString
MixedAssignment on line %d: Unable to determine the type that $bio is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Support\HtmlString::__construct cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
