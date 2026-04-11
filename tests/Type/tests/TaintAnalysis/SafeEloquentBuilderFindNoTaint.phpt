--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class Post extends \Illuminate\Database\Eloquent\Model {}

/** find($id) uses a parameterized WHERE clause — user-supplied id cannot inject. */
function safeFindById(\Illuminate\Http\Request $request): void {
    /** @psalm-suppress MixedAssignment */
    $id = $request->input('id');

    Post::find($id);
    Post::findOrFail($id);
}
?>
--EXPECTF--
