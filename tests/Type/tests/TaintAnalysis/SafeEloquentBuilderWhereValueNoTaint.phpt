--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class Article extends \Illuminate\Database\Eloquent\Model {}

/**
 * Eloquent where(), orWhere(), firstWhere() bind values via PDO — cannot inject.
 * Covers positional (2-arg and 3-arg) and array-condition forms.
 *
 * @psalm-suppress MixedAssignment
 */
function safeEloquentWhereValue(\Illuminate\Http\Request $request): void {
    $value = $request->input('search');

    Article::where('title', '=', $value);
    Article::orWhere('slug', $value);
    Article::firstWhere('title', $value);

    // Array-condition form: values inside $column array, still parameterized by PDO.
    Article::where(['title' => $value])->first();
    Article::firstWhere(['title' => $value]);
}
?>
--EXPECTF--
