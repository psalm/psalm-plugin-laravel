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
%ATaintedHtml on line %d: Detected tainted HTML
