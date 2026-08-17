--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showAuthor(\Illuminate\Http\Request $request) {
    $authorName = $request->input('author');
    return response($authorName);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method showAuthor does not have a return type, expecting Illuminate\Http\Response
MixedAssignment on line %d: Unable to determine the type that $authorName is being assigned to
MixedArgument on line %d: Argument 1 of response cannot be mixed, expecting Illuminate\Contracts\View\View|array<array-key, mixed>|null|string
TaintedHtml on line %d: Detected tainted HTML
