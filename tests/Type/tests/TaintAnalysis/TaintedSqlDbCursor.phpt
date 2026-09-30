--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function showPosts(\Illuminate\Http\Request $request): void {
    $postId = $request->input('id');
    foreach (DB::cursor("SELECT * FROM posts WHERE id = " . $postId) as $_post) {
    }
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $postId is being assigned to
TaintedSql on line %d: Detected tainted SQL
MixedOperand on line %d: Right operand cannot be mixed
