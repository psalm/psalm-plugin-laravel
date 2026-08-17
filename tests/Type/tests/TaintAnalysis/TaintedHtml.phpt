--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderComment(\Illuminate\Http\Request $request) {
    $comment = $request->input('comment');

    return new \Illuminate\Http\Response($comment);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method renderComment does not have a return type, expecting Illuminate\Http\Response
MixedAssignment on line %d: Unable to determine the type that $comment is being assigned to
TaintedHtml on line %d: Detected tainted HTML
