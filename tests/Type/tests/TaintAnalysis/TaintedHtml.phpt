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
%ATaintedHtml on line %d: Detected tainted HTML
