--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function displayName(\Illuminate\Http\Request $request): void {
    /** @var string $name */
    $name = $request->input('name');

    echo \Illuminate\Support\Str::of($name);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
