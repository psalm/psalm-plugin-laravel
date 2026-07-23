--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * whereLike() binds $value via addBinding(); only $column is wrapped as a raw identifier. The new
 * stubs (Laravel >= 11.17) therefore put `@psalm-taint-sink sql $column` on the whole whereLike
 * family and leave $value unsinked, so a tainted value must not be flagged on any of the four
 * variants. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function safeWhereLikeFamilyValues(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $term = (string) $request->input('term');

    $builder->whereLike('name', "%{$term}%");
    $builder->orWhereLike('name', $term);
    $builder->whereNotLike('name', $term);
    $builder->orWhereNotLike('name', $term);
}
?>
--EXPECTF--
