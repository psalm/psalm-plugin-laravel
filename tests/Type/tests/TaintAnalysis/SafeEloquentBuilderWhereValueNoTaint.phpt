--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class Article extends \Illuminate\Database\Eloquent\Model {}

/**
 * Eloquent where(), orWhere(), firstWhere() bind values via PDO — cannot inject.
 *
 * @psalm-suppress MixedAssignment
 */
function safeEloquentWhereValue(\Illuminate\Http\Request $request): void {
    $value = $request->input('search');

    Article::where('title', '=', $value);
    Article::orWhere('slug', $value);
    Article::firstWhere('title', $value);
}
?>
--EXPECTF--
