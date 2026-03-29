--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function displayWrapped(\Illuminate\Http\Request $request): void {
    /** @var string $tag */
    $tag = $request->input('tag');

    echo \Illuminate\Support\Str::of('content')->wrap($tag);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
