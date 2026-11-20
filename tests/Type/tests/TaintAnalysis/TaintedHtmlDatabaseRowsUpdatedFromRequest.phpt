--CONFLICTS--
taint-TaintedHtmlDatabaseRowsUpdatedFromRequest
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The rows select() gives hold what an update with named bindings stored.
 * The unique CONFLICTS key runs this file in its own Psalm run: the tables are shared by the whole
 * program, and another fixture's unprepared() statement may write any column.
 */
function updateBio(\Illuminate\Http\Request $request): void {
    DB::update('UPDATE users SET bio = :bio WHERE id = :id', ['bio' => $request->input('bio'), 'id' => 1]);
}

function listBios(): void {
    foreach (DB::select('SELECT bio FROM users') as $user) {
        echo (string) $user->bio;
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
