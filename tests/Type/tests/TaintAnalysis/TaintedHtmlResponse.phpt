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
TaintedHtml on line %d: Detected tainted HTML
