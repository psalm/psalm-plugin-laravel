--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Connection::escape() removes SQL taint but must NOT remove HTML taint.
 * The escaped value is still user-controlled and dangerous in HTML context.
 *
 * @psalm-suppress MixedAssignment, MixedArgument
 */
function escapePreservesHtmlTaint(\Illuminate\Http\Request $request): void {
    $connection = new \Illuminate\Database\Connection(new PDO('sqlite::memory:'));
    $userInput = $request->input('name');

    $escaped = $connection->escape($userInput);

    echo $escaped;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
