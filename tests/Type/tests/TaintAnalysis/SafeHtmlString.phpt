--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderSafeBio(\Illuminate\Http\Request $request): \Illuminate\Support\HtmlString {
    /** @var string $bio */
    $bio = $request->input('bio');

    return new \Illuminate\Support\HtmlString(e($bio));
}
?>
--EXPECTF--
