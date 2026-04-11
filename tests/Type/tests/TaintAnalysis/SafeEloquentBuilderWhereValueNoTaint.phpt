--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class Article extends \Illuminate\Database\Eloquent\Model {}

/**
 * Eloquent where(), orWhere(), whereNot(), orWhereNot(), firstWhere() use parameterized
 * queries for values — user-supplied values cannot inject SQL.
 *
 * @psalm-suppress MixedAssignment
 */
function safeEloquentWhereValue(\Illuminate\Http\Request $request): void {
    $value = $request->input('search');

    Article::where('title', '=', $value);
    Article::orWhere('slug', $value);
    Article::whereNot('status', $value);
    Article::orWhereNot('status', $value);
    Article::firstWhere('title', $value);
}
?>
--EXPECTF--
