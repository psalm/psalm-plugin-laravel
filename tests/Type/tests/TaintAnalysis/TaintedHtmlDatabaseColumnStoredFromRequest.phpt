--CONFLICTS--
taint-TaintedHtmlDatabaseColumnStoredFromRequest
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * What a raw query stores in a column is what the queries reading that column give, and only that.
 * The unique CONFLICTS key runs this file in its own Psalm run: the tables are shared by the whole
 * program, and another fixture's unprepared() statement may write any column.
 */
function storeComment(\Illuminate\Http\Request $request): void {
    DB::insert('INSERT INTO comments (author, body) VALUES (?, ?)', ['admin', $request->input('body')]);
}

function showComment(): void {
    $comment = DB::selectOne('SELECT author, body FROM comments');
    if ($comment === null) {
        return;
    }
    echo (string) $comment->body;
    echo (string) $comment->author;
    echo (string) DB::scalar('SELECT title FROM comments');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
