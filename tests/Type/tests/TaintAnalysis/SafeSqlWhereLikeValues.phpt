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
 * $value is typed `mixed`, not `string`, to match the where() sibling stub and the plugin's
 * loose-param / strict-return policy. Laravel's own docblock types it `string` (Psalm reads that off
 * reflection, with or without a stub), which flags idiomatic calls like
 * whereLike('name', $request->query('q')) (query() is string|array|null) as PossiblyInvalidArgument.
 * The widening is signature and policy alignment, not a claim of universal runtime validity: an array
 * value still mismatches the single `?` placeholder at execute time. The raw-value calls below pin
 * the widening so a revert to `string` fails here. #1300
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

    // Raw, un-cast query-string input is string|array|null; a `mixed` $value accepts it with no
    // PossiblyInvalidArgument. Teeth for the widening: a `string` param flags all four here.
    $builder->whereLike('name', $request->query('q'));
    $builder->orWhereLike('name', $request->query('q'));
    $builder->whereNotLike('name', $request->query('q'));
    $builder->orWhereNotLike('name', $request->query('q'));
}
?>
--EXPECTF--
