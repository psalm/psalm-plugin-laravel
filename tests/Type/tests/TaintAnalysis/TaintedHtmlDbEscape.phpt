--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * DB::escape() removes SQL taint but must NOT remove HTML taint.
 * The escaped value is still user-controlled and dangerous in HTML context.
 *
 * @psalm-suppress MixedAssignment, MixedArgument
 */
function dbEscapePreservesHtmlTaint(\Illuminate\Http\Request $request): void {
    $userInput = $request->input('name');

    $escaped = \Illuminate\Support\Facades\DB::escape($userInput);

    echo $escaped;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
