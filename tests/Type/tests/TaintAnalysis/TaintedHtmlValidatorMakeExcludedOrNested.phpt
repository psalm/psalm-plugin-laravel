--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * A field the validator may exclude, or a rule of a nested attribute, doesn't validate the value under the key.
 */
function showExcluded(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent, 'guest' => '1'],
        ['agent' => ['exclude_if:guest,1', 'regex:#^[a-z]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    echo $agent;
}

function showNested(\Illuminate\Http\Request $request): void {
    $agent = trim($request->header('User-Agent', ''));
    $validator = \Illuminate\Support\Facades\Validator::make(
        ['agent' => $agent],
        ['agent.name' => ['regex:#^[a-z]+$#']],
    );
    if ($validator->fails()) {
        return;
    }
    echo $agent;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
