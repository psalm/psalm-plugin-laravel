--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class EloquentFindPost extends \Illuminate\Database\Eloquent\Model {}

/**
 * find($id) with a scalar id uses a parameterized WHERE clause — user-supplied id cannot inject.
 *
 * @psalm-suppress MixedAssignment
 */
function safeFindById(\Illuminate\Http\Request $request): void {
    $id = $request->input('id');

    EloquentFindPost::find($id);
    EloquentFindPost::findOrFail($id);
    EloquentFindPost::findOrNew($id);
    EloquentFindPost::findSole($id);
}

/** @psalm-suppress MixedAssignment, MixedArgument */
function safeFindMany(\Illuminate\Http\Request $request): void {
    $ids = $request->input('ids');

    EloquentFindPost::findMany($ids);
}
?>
--EXPECTF--
